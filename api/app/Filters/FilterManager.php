<?php

namespace App\Filters;

use App\Elasticsearch\ElasticQueryBuilder;
use App\Filters\Contracts\FilterHandlerInterface;
use App\Models\Filter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FilterManager
{
    /**
     * Cache TTL in minutes for different types
     */
    protected const CACHE_TTL = [
        'filters' => 60,          // Active filters list
        'predefined' => 1440,     // Predefined values (24 hours)
        'location' => 1440,       // Location hierarchies (24 hours)
        'elasticsearch' => 30,     // Dynamic values (30 minutes)
    ];

    public function __construct(
        protected FilterHandlerFactory $factory
    ) {}

    /**
     * Get handler for a specific filter
     */
    public function getHandler(Filter $filter): FilterHandlerInterface
    {
        return $this->factory->make($filter);
    }

    /**
     * Get active filters
     *
     * @return Collection<Filter>
     */
    public function getActiveFilters(): Collection
    {
        // Use Registry as the single source of truth
        $configs = \App\Services\FilterRegistry::getFilters();

        return collect($configs)->map(function ($config) {
            $attributes = [
                'filter_id' => $config['id'],
                'name' => $config['label'],
                'group' => $config['group'], // Group Name
                'filter_group_id' => 1, // Placeholder
                'value_source' => $config['data_source'],
                'value_type' => $config['type'],
                'input_type' => $config['input'],
                'is_searchable' => $config['search']['enabled'] ?? false,
                'allows_exclusion' => $config['filtering']['supports_exclusion'] ?? false,
                'settings' => [
                    'fields' => $config['fields'],
                    'search_fields' => $config['search']['suggest_fields'] ?? [],
                    'target_model' => in_array('company', $config['applies_to']) ? \App\Models\Company::class : (in_array('contact', $config['applies_to']) ? \App\Models\Contact::class : \App\Models\Company::class),
                ],
                'sort_order' => $config['sort_order'],
                'is_active' => $config['active'],
                'supports_value_lookup' => in_array($config['data_source'], ['elasticsearch', 'predefined']),
                'filter_type' => $config['type'],
            ];

            $filter = new Filter($attributes);
            $filter->type = $config['type']; // Add type for factory dispatch
            return $filter;
        });
    }

    /**
     * Get a specific filter by ID
     */
    public function getFilter(string $filterId): ?Filter
    {
        return $this->getActiveFilters()->firstWhere('filter_id', $filterId);
    }

    /**
     * Apply multiple filters to a query
     *
     * @param  array  $filters  Array of [filter_id => value] (DSL)
     */
    public function applyFilters(ElasticQueryBuilder $query, array $filters, string $context = 'company'): ElasticQueryBuilder
    {
        $orderedFilters = $this->sortFilters($filters);

        foreach ($orderedFilters as $filterId => $value) {
            $filterModel = $this->getFilter($filterId);
            if (! $filterModel) {
                \Log::warning('FilterManager: Unknown filter ID ignored', ['filter_id' => $filterId]);
                continue;
            }

            $normalized = $this->normalizeFilterValue($value);

            // Enforce exclusion support
            if (!empty($normalized['exclude'] ?? []) && ! ($filterModel->allows_exclusion ?? false)) {
                \Log::warning('FilterManager: Exclusions not supported for filter, removing', ['filter_id' => $filterId]);
                $normalized['exclude'] = [];
            }

            $handler = $this->getHandler($filterModel);
            $query = $handler->apply($query, $normalized, $context);
        }

        return $query;
    }

    protected function sortFilters(array $filters): array
    {
        // Priority: boolean > range > terms (keyword) > term (text/direct)
        $priorityMap = [
            'boolean' => 1,
            'range' => 2,
            'date' => 2,
            'keyword' => 3,
            'text' => 4,
            'direct' => 4,
        ];

        $sortedKeys = array_keys($filters);
        usort($sortedKeys, function ($a, $b) use ($priorityMap) {
            $filterA = $this->getFilter($a);
            $filterB = $this->getFilter($b);
            
            $typeA = $filterA->type ?? 'text';
            $typeB = $filterB->type ?? 'text';

            $pA = $priorityMap[$typeA] ?? 10;
            $pB = $priorityMap[$typeB] ?? 10;

            return $pA <=> $pB;
        });

        $sortedFilters = [];
        foreach ($sortedKeys as $key) {
            $sortedFilters[$key] = $filters[$key];
        }

        return $sortedFilters;
    }

    protected function normalizeFilterValue(mixed $value): array
    {
        $out = [
            'include' => [],
            'exclude' => [],
            'range' => null,
            'presence' => null,
            'operator' => null,
        ];

        if (is_array($value)) {
            if (isset($value['include']) && is_array($value['include'])) {
                $out['include'] = array_values(array_filter($value['include'], fn($v) => is_string($v) || is_numeric($v)));
            }
            if (isset($value['exclude']) && is_array($value['exclude'])) {
                $out['exclude'] = array_values(array_filter($value['exclude'], fn($v) => is_string($v) || is_numeric($v)));
            }
            if (isset($value['range']) && is_array($value['range'])) {
                $min = $value['range']['min'] ?? null;
                $max = $value['range']['max'] ?? null;
                $out['range'] = ['min' => is_numeric($min) ? (float) $min : null, 'max' => is_numeric($max) ? (float) $max : null];
            }
            if (isset($value['presence'])) {
                $presence = $value['presence'];
                if (in_array($presence, ['any', 'known', 'unknown'], true)) {
                    $out['presence'] = $presence;
                }
            }
            if (isset($value['operator'])) {
                $op = $value['operator'];
                if (in_array($op, ['and', 'or'], true)) {
                    $out['operator'] = $op;
                }
            }
        } elseif (is_string($value) || is_numeric($value)) {
            $out['include'] = [$value];
        }

        return $out;
    }

    /**
     * Get values for a specific filter
     */
    public function getFilterValues(string $filterId, ?string $search = null, int $page = 1, int $perPage = 10): array
    {
        $filter = $this->getFilter($filterId);
        if (! $filter) {
            throw new ModelNotFoundException("Filter not found: {$filterId}");
        }

        $handler = $this->getHandler($filter);

        // Don't cache if searching
        if ($search) {
            return $handler->getValues($search, $page, $perPage);
        }

        // Get cache key and TTL based on filter type
        $cacheKey = $this->getValuesCacheKey($filter, $page, $perPage);
        $cacheTTL = $this->getValuesCacheTTL($filter);

        // Cache values if needed
        return Cache::remember($cacheKey, $cacheTTL, function () use ($handler, $page, $perPage) {
            return $handler->getValues(null, $page, $perPage);
        });
    }

    /**
     * Generate cache key for filter values
     */
    protected function getValuesCacheKey(Filter $filter, int $page, int $perPage): string
    {
        return "filters:values:{$filter->filter_id}:{$page}:{$perPage}";
    }

    /**
     * Get cache TTL based on filter type
     */
    protected function getValuesCacheTTL(Filter $filter): int
    {
        return match ($filter->value_source) {
            'predefined' => self::CACHE_TTL['predefined'],
            'specialized' => match ($filter->value_type) {
                'location' => self::CACHE_TTL['location'],
                default => 0
            },
            'elasticsearch' => self::CACHE_TTL['elasticsearch'],
            default => 0 // Don't cache other types
        };
    }
}
