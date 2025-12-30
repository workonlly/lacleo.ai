<?php

namespace App\Filters\Handlers;

use App\Elasticsearch\ElasticQueryBuilder;

class ElasticsearchFilterHandler extends AbstractFilterHandler
{
    public function validateValues(array $values): bool
    {
        if (empty($values)) {
            return false;
        }

        return collect($values)->every(function ($value) {
            $v = $value['value'] ?? null;
            if (is_null($v)) {
                return false;
            }
            if (is_numeric($v)) {
                return true;
            }
            if (is_string($v)) {
                return trim($v) !== '';
            }

            return false;
        });
    }

    /**
     * Get the appropriate field for the current context or target model
     */
    protected function getField(string $context): string
    {
        $fields = $this->filter->settings['fields'] ?? [];
        
        // Try exact context match
        if (isset($fields[$context]) && !empty($fields[$context])) {
            return $fields[$context][0];
        }

        // Fallback: try to derive context from target model if not provided or found
        $targetModel = $this->filter->settings['target_model'] ?? null;
        if ($targetModel === \App\Models\Contact::class) {
            return $fields['contact'][0] ?? $this->filter->elasticsearch_field ?? '';
        }
        
        return $fields['company'][0] ?? $this->filter->elasticsearch_field ?? '';
    }

    /**
     * Get possible values for this filter
     */
    public function getValues(?string $search = null, int $page = 1, int $perPage = 10): array
    {
        $targetModel = $this->filter->getTargetModelOrFail();
        $elastic = $targetModel::elastic();

        $settings = $this->filter->settings;
        $fieldType = $this->filter->type ?? ($settings['field_type'] ?? 'text');
        
        // Determine field based on target model context
        $context = ($targetModel === \App\Models\Contact::class) ? 'contact' : 'company';
        $field = $this->getField($context);

        if (empty($field)) {
            return $this->emptyPaginatedResponse($page, $perPage);
        }

        if (! empty($search)) {
            if ($fieldType === 'keyword') {
                $elastic->must([
                    'bool' => [
                        'should' => [
                            [
                                'prefix' => [
                                    $field => $search,
                                ],
                            ],
                            [
                                'prefix' => [
                                    $field.'.lowercase' => strtolower($search),
                                ],
                            ],
                        ],
                    ],
                ]);
            } else {
                $searchFields = $this->filter->settings['search_fields'] ?? [];
                $elastic->multiMatch(
                    query: $search,
                    fields: $searchFields,
                    options: [
                        'type' => 'best_fields',
                        'operator' => 'and',
                        'minimum_should_match' => '70%',
                    ]
                );
            }
        }

        $elastic->termsAggregation(
            'distinct_values',
            $fieldType === 'keyword'
                ? $field
                : $field.'.keyword',
            [
                'size' => 10000,
                'order' => ['_key' => 'asc'],
            ]
        );

        $result = $elastic->paginate($page, $perPage);

        $values = collect($result['aggregations']['distinct_values']['buckets'] ?? [])
            ->map(fn ($bucket) => [
                'id' => $bucket['key'],
                'name' => $bucket['key'],
            ])
            ->values()
            ->toArray();

        return $this->paginateResults($values, $page, $perPage);
    }

    /**
     * Apply the elasticsearch filter to the query
     */
    public function apply(ElasticQueryBuilder $query, array $values, string $context = 'company'): ElasticQueryBuilder
    {
        $field = $this->getField($context);
        if (empty($field)) {
            return $query;
        }

        $include = $values['include'] ?? [];
        $exclude = $values['exclude'] ?? [];
        $range = $values['range'] ?? null;
        $presence = $values['presence'] ?? null;
        $operator = $values['operator'] ?? 'and';

        if ($presence === 'known') {
            $query->filter(['exists' => ['field' => $field]]);
        } elseif ($presence === 'unknown') {
            $query->mustNot(['exists' => ['field' => $field]]);
        }

        // Range
        if (is_array($range) && (isset($range['min']) || isset($range['max']))) {
            $clause = ['range' => [$field => array_filter([
                'gte' => isset($range['min']) ? (float) $range['min'] : null,
                'lte' => isset($range['max']) ? (float) $range['max'] : null,
            ])]];
            $query->filter($clause);
        }

        // Include values
        if (!empty($include)) {
            $this->applyInclude($query, $field, $include, $operator);
        }

        // Exclude values
        if (!empty($exclude)) {
            $this->applyExclude($query, $field, $exclude);
        }

        return $query;
    }

    protected function applyInclude(ElasticQueryBuilder $query, string $field, array $values, string $operator): void
    {
        $settings = $this->filter->settings;
        $fieldType = $this->filter->type ?? ($settings['field_type'] ?? 'text');
        if ($fieldType === 'keyword') {
            $query->filter(['terms' => [$field => $values]]);
            return;
        }
        if ($operator === 'or') {
            $query->filter([
                'bool' => [
                    'should' => array_map(fn($v) => ['match_phrase' => [$field => $v]], $values),
                    'minimum_should_match' => 1,
                ],
            ]);
        } else {
            foreach ($values as $v) {
                $query->filter(['match_phrase' => [$field => $v]]);
            }
        }
    }

    protected function applyExclude(ElasticQueryBuilder $query, string $field, array $values): void
    {
        $settings = $this->filter->settings;
        $fieldType = $this->filter->type ?? ($settings['field_type'] ?? 'text');
        if ($fieldType === 'keyword') {
            $query->mustNot(['terms' => [$field => $values]]);
            return;
        }
        foreach ($values as $v) {
            $query->mustNot(['match_phrase' => [$field => $v]]);
        }
    }
}
