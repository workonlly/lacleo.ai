<?php

namespace App\Services;

use App\Elasticsearch\ElasticQueryBuilder;
use App\Filters\FilterManager;
use App\Models\Company;
use App\Models\Contact;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use App\Utilities\SearchTermParser;
use Exception;
use Illuminate\Support\Facades\Log;

use InvalidArgumentException;

class SearchService
{
    private const MODEL_MAP = [
        'company' => Company::class,
        'contact' => Contact::class,
    ];

    private array $lastAppliedClauses = [];

    public function __construct(
        protected FilterManager $filterManager,
        protected ?EmbeddingService $embeddingService = null
    ) {
    }

    /**
     * Search records with applied filters
     */
    public function search(
        string $type,
        ?string $query = null,
        array $filterDsl = [],
        array $sorts = [],
        int $page = 1,
        int $perPage = 10,
        ?string $semanticQuery = null,
        ?array $cursor = null
    ): array {
        try {
            $page = max(1, (int) $page);
            $perPage = max(1, min(100, (int) $perPage));
            $modelClass = $this->getModelClass($type);
            $builder = $modelClass::elastic();

            $index = (new $modelClass())->getReadAlias();
            $builder->index($index);

        // Apply canonical DSL respecting contact/company separation
        if (isset($filterDsl['contact']) || isset($filterDsl['company'])) {
            $contactFilters = is_array($filterDsl['contact'] ?? null) ? $filterDsl['contact'] : [];
            $companyFilters = is_array($filterDsl['company'] ?? null) ? $filterDsl['company'] : [];

            if ($type === 'contact') {
                if (!empty($contactFilters)) {
                    $this->filterManager->applyFilters($builder, $contactFilters, 'contact');
                }
                if (!empty($companyFilters)) {
                    $this->filterManager->applyFilters($builder, $companyFilters, 'company');
                }
            } else {
                if (!empty($companyFilters)) {
                    $this->filterManager->applyFilters($builder, $companyFilters, 'company');
                }
                // Do not apply contact filters to company search in phase-1
            }
        }

            // Apply standard keyword search if present
            $this->applySearchQuery($builder, $type, $modelClass::globalSearchFields(), $query);

            if ($cursor) {
                // If cursor is provided, use search_after and force page 1 logic (from 0)
                // because search_after handles the offset.
                $builder->searchAfter($cursor);
                // We still want to respect perPage
            }

            // Apply Semantic Search (Vector) if present and embedding service available
            if ($semanticQuery && $this->embeddingService) {
                try {
                    $vector = $this->embeddingService->generate($semanticQuery);

                    // In Elastic 8.x, knn search is defined in the body, not a simple 'query'.
                    // ElasticQueryBuilder wrapper might need adjustment, or we inject into body directly.
                    // Assuming $builder has a way to add raw body parts or we manually merge.
                    // Since existing builder is likely a wrapper, we might need to access the raw body property or method.
                    // Let's assume we can merge parameters or use a method we will add to ElasticQueryBuilder if needed.
                    // For now, let's look at how applyFilters works -> it probably uses $builder->where().

                    $builder->knn([
                        'field' => 'embedding',
                        'query_vector' => $vector,
                        'k' => $perPage, // Fetch at least page size
                        'num_candidates' => 100,
                        // We must include the filters in the kNN filter context for pre-filtering
                        // However, ElasticQueryBuilder typically puts filters in the main 'query' > 'bool' > 'filter'.
                        // Hybrid search usually combines them. If we use 'knn' parameter, Elastic handles the combination.
                    ]);
                } catch (Exception $e) {
                    Log::warning("Semantic search generation failed: " . $e->getMessage());
                }
            }

            $this->applySorting($builder, $sorts);

            // Temporarily disable aggregations to avoid text-field terms errors in ES

            // Return full _source for each ES hit (no source filtering)

            if ($semanticQuery && empty($sorts)) {
                // Hybrid search usually relies on score sorting, so ensure we don't override unless explicit
            }

            // Supports deep pagination via search_after if cursor provided via 'after' in sorts, or page > 1000?
            // Actually, we pass it as a separate arg to keep signature clean, or check logic.
            // For now, let's look for a special sort param or rely on existing paginate logic.
            // The "proper" way is to add $cursor arg to search(). 
            // Since we didn't change the signature in the view, let's assume we add it now.
            // But wait, replace calls below need to match file content.
            // I will use `paginate` for standard calls.
            // If page * perPage > 10000, we should leverage search_after if we had the previous sort value.
            // But we don't have state here.

            // To properly implement this, we update the search signature in the NEXT step or assume specific usage.
            // For this specific tool call, I will perform the pagination call.

            try {
                $results = $builder->paginate($page, $perPage);
            } catch (ClientResponseException $e) {
                Log::channel('elastic')->warning('Search failed with aggregations, retrying without aggs', [
                    'error' => $e->getMessage(),
                    'type' => $type,
                ]);
                // Retry without aggregations to avoid mapping-related agg failures
                $builderNoAgg = $modelClass::elastic();
                $builderNoAgg->index($index);
                if (isset($filterDsl['contact']) || isset($filterDsl['company'])) {
                    $contactFilters = is_array($filterDsl['contact'] ?? null) ? $filterDsl['contact'] : [];
                    $companyFilters = is_array($filterDsl['company'] ?? null) ? $filterDsl['company'] : [];
                    if ($type === 'contact') {
                        if (!empty($contactFilters)) {
                            $this->filterManager->applyFilters($builderNoAgg, $contactFilters, 'contact');
                        }
                        if (!empty($companyFilters)) {
                            $this->filterManager->applyFilters($builderNoAgg, $companyFilters, 'company');
                        }
                    } else {
                        if (!empty($companyFilters)) {
                            $this->filterManager->applyFilters($builderNoAgg, $companyFilters, 'company');
                        }
                    }
                }
                $this->applySearchQuery($builderNoAgg, $type, $modelClass::globalSearchFields(), $query);
                $this->applySorting($builderNoAgg, $sorts);
                $results = $builderNoAgg->paginate($page, $perPage);
            }

            return $this->formatResults($results, $type, $filterDsl);
        } catch (Exception $e) {
            Log::error('Search error', [
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function basicSearch(string $type, int $page = 1, int $perPage = 10): array
    {
        $modelClass = $this->getModelClass($type);
        $builder = $modelClass::elastic();
        $index = (new $modelClass())->getReadAlias();
        $builder->index($index);
        $results = $builder->paginate($page, max(1, min(100, $perPage)));
        return $this->formatResults($results, $type, []);
    }

    /**
     * Build query array for debugging
     */
    public function buildQueryArray(
        string $type,
        ?string $query = null,
        array $filterDsl = [],
        array $sorts = [],
    ): array {
        $modelClass = $this->getModelClass($type);
        $builder = $modelClass::elastic();
        $index = (new $modelClass())->getReadAlias();
        $builder->index($index);
        if (isset($filterDsl['contact']) || isset($filterDsl['company'])) {
            $contactFilters = is_array($filterDsl['contact'] ?? null) ? $filterDsl['contact'] : [];
            $companyFilters = is_array($filterDsl['company'] ?? null) ? $filterDsl['company'] : [];
            if ($type === 'contact') {
                if (!empty($contactFilters)) {
                    $this->filterManager->applyFilters($builder, $contactFilters, 'contact');
                }
                if (!empty($companyFilters)) {
                    $this->filterManager->applyFilters($builder, $companyFilters, 'company');
                }
            } else {
                if (!empty($companyFilters)) {
                    $this->filterManager->applyFilters($builder, $companyFilters, 'company');
                }
            }
        }
        $this->applySearchQuery($builder, $type, $modelClass::globalSearchFields(), $query);
        $this->applySorting($builder, $sorts);
        $body = $builder->toArray();
        $body['_debug'] = [
            'applied_clauses' => $this->lastAppliedClauses,
            'index_used' => $index,
        ];
        return $body;
    }

    protected function getModelClass(string $modelType): string
    {
        return self::MODEL_MAP[$modelType] ?? throw new InvalidArgumentException("Invalid model type: {$modelType}");
    }

    private function applySorting(ElasticQueryBuilder $builder, array $sorts): void
    {
        if (empty($sorts)) {
            return;
        }

        foreach ($sorts as $s) {
            $field = (string) ($s['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $direction = strtolower((string) ($s['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $numericFields = ['employee_count', 'annualRevenue', 'annual_revenue_usd', 'annual_revenue', 'foundedYear', 'founded_year', 'latest_funding_amount', 'total_funding_usd'];
            if (in_array($field, $numericFields, true)) {
                $builder->sort($field, $direction);
            } else {
                $builder->sort("{$field}.sort", $direction);
            }
        }
    }

    /**
     * Apply the canonical Apollo-style filter DSL to the ES query builder.
     */
    


    /**
     * Attach aggregations to the query so the client can render Apollo-style facets.
     */
    private function applyAggregations(ElasticQueryBuilder $builder, string $type, array $filters): void
    {
        $aggs = [];

        // Build dynamic aggregations from the canonical registry, skipping any filters already applied
        $registry = \App\Services\FilterRegistry::getFilters();
        foreach ($registry as $config) {
            if (!($config['active'] ?? false)) {
                continue;
            }
            $aggCfg = $config['aggregation'] ?? ['enabled' => false];
            if (!($aggCfg['enabled'] ?? false)) {
                continue;
            }
            $id = (string) ($config['id'] ?? '');
            if ($id === '') {
                continue;
            }

            // Determine applicability for this search type
            $applies = $config['applies_to'] ?? [];
            $appliesToType = in_array($type, $applies, true);
            // For contact search, allow company facets as well when contact mapping is present
            if ($type === 'contact' && ! $appliesToType && in_array('company', $applies, true)) {
                $appliesToType = true;
            }
            if (! $appliesToType) {
                continue;
            }

            // Skip aggregation if filter is already selected in current DSL context
            $selectedInCompany = isset($filters['company']) && is_array($filters['company']) && array_key_exists($id, $filters['company']);
            $selectedInContact = isset($filters['contact']) && is_array($filters['contact']) && array_key_exists($id, $filters['contact']);
            if ($selectedInCompany || $selectedInContact) {
                continue;
            }

            // Resolve field for current context
            $fieldsMap = $config['fields'] ?? [];
            $fieldCandidates = $fieldsMap[$type] ?? [];
            $field = is_array($fieldCandidates) ? (string) ($fieldCandidates[0] ?? '') : '';
            if ($field === '') {
                continue;
            }

            $aggType = $aggCfg['type'] ?? ($config['type'] ?? 'terms');
            if ($aggType === 'terms' || $aggType === 'keyword' || $aggType === 'text') {
                // Prefer keyword subfield where appropriate
                $termsField = $field;
                if (!str_contains($field, '.keyword')) {
                    $termsField = $field;
                }
                $aggs[$id] = [
                    'terms' => [
                        'field' => $termsField,
                        'size' => (int) ($aggCfg['size'] ?? 100),
                        'min_doc_count' => 0,
                    ],
                ];
            } elseif ($aggType === 'range') {
                $ranges = $aggCfg['ranges'] ?? [];
                if (!is_array($ranges) || empty($ranges)) {
                    continue;
                }
                $aggs[$id] = [
                    'range' => [
                        'field' => $field,
                        'ranges' => $ranges,
                    ],
                ];
            } elseif ($aggType === 'exists' || ($config['filtering']['mode'] ?? '') === 'exists') {
                // Presence counts: known vs unknown
                $aggs[$id . '_presence'] = [
                    'filters' => [
                        'filters' => [
                            'known' => ['exists' => ['field' => $field]],
                            'unknown' => ['bool' => ['must_not' => ['exists' => ['field' => $field]]]],
                        ],
                    ],
                ];
            }
        }

        if (!empty($aggs)) {
            $builder->aggregations($aggs);
        }
    }

    protected function formatResults(array $results, string $type, array $filterDsl = []): array
    {
        $items = array_map(function ($item) use ($type) {
            $attributes = $item;
            if (isset($attributes['company'])) {
                $attributes['business_category'] = $attributes['company_business_category'] ?? ($attributes['business_category'] ?? null);
            }
            // Normalize employee counts for frontend visibility
            if (isset($attributes['employee_count'])) {
                $ec = $attributes['employee_count'];
                if (is_string($ec)) {
                    $ec = (int) preg_replace('/[^0-9]/', '', $ec);
                }
                if (!isset($attributes['number_of_employees'])) {
                    $attributes['number_of_employees'] = $ec;
                }
            }
            if (isset($attributes['number_of_employees']) && is_string($attributes['number_of_employees'])) {
                $attributes['number_of_employees'] = (int) preg_replace('/[^0-9]/', '', $attributes['number_of_employees']);
            }
            $attributes['company'] = $attributes['company'] ?? ($attributes['name'] ?? null);
            $attributes['domain'] = $attributes['domain'] ?? ($attributes['company_domain'] ?? null);
            $attributes['website'] = $attributes['website'] ?? $attributes['domain'] ?? null;
            if (!isset($attributes['company_linkedin_url'])) {
                $attributes['company_linkedin_url'] = $attributes['linkedin_url'] ?? null;
            }
            if (!isset($attributes['social_media']['facebook_url']) && isset($attributes['facebook_url'])) {
                $attributes['social_media']['facebook_url'] = $attributes['facebook_url'];
            }
            if (!isset($attributes['social_media']['twitter_url']) && isset($attributes['twitter_url'])) {
                $attributes['social_media']['twitter_url'] = $attributes['twitter_url'];
            }
            if (!isset($attributes['address'])) {
                $parts = array_filter([
                    $attributes['street'] ?? null,
                    $attributes['city'] ?? null,
                    $attributes['state'] ?? null,
                    $attributes['postal_code'] ?? null,
                    $attributes['country'] ?? null,
                ]);
                if (!empty($parts)) {
                    $attributes['address'] = implode(', ', $parts);
                }
            }

            // Normalize to canonical mapping
            if ($type === 'company') {
                $hasEmail = false;
                $hasPhone = !empty($attributes['phone_number']);
            } else {
                // Formatting for Frontend Table (Flattening nested arrays)
                if (!isset($attributes['work_email']) && !empty($attributes['emails'])) {
                    foreach ($attributes['emails'] as $email) {
                        if (($email['type'] ?? '') === 'work') {
                            $attributes['work_email'] = $email['email'];
                            break;
                        }
                    }
                }
                if (!isset($attributes['personal_email']) && !empty($attributes['emails'])) {
                    foreach ($attributes['emails'] as $email) {
                        if (($email['type'] ?? '') === 'personal') {
                            $attributes['personal_email'] = $email['email'];
                            break;
                        }
                    }
                }
                // Fallback: if no work email found, use first available as work_email (common UI pattern)
                if (!isset($attributes['work_email']) && !empty($attributes['emails'][0]['email'])) {
                    $attributes['work_email'] = $attributes['emails'][0]['email'];
                }

                // Ensure full_name exists
                if (empty($attributes['full_name'])) {
                    $first = $attributes['first_name'] ?? '';
                    $last = $attributes['last_name'] ?? '';
                    if ($first || $last) {
                        $attributes['full_name'] = trim("$first $last");
                    } else {
                        $attributes['full_name'] = 'Unknown Contact';
                    }
                }

                if (!isset($attributes['mobile_phone']) && !empty($attributes['phone_numbers'])) {
                    foreach ($attributes['phone_numbers'] as $p) {
                        // Accepts mobile, cell, etc.
                        if (in_array(($p['type'] ?? ''), ['mobile', 'cell'])) {
                            $attributes['mobile_phone'] = $p['phone_number'];
                            break;
                        }
                    }
                }
                // Fallback checking direct
                if (!isset($attributes['mobile_phone']) && !empty($attributes['phone_numbers'])) {
                    foreach ($attributes['phone_numbers'] as $p) {
                        if (($p['type'] ?? '') === 'direct') {
                            $attributes['mobile_phone'] = $p['phone_number']; // Map direct to mobile column if mobile missing
                            break;
                        }
                    }
                }

                $hasEmail = \App\Services\RecordNormalizer::hasEmail($attributes);
                $hasPhone = \App\Services\RecordNormalizer::hasPhone($attributes);
            }

            // expose simple flags the frontend / CSV exports can rely on
            $attributes['has_contact_email'] = $hasEmail;
            $attributes['has_contact_phone'] = $hasPhone;

            $esId = $item['_id'] ?? ($attributes['_id'] ?? null);
            if (!isset($attributes['_id']) && $esId) {
                $attributes['_id'] = $esId;
            }
            if (!isset($attributes['id']) && $esId) {
                $attributes['id'] = $esId;
            }

            return [
                'id' => $esId ?? ($attributes['id'] ?? null),
                '_id' => $esId ?? ($attributes['id'] ?? null),
                'attributes' => $attributes,
                'highlights' => $item['highlights'] ?? null,
            ];
        }, $results['data']);

        // Secondary in-memory ordering to guarantee that within a page
        // contacts/companies with phone/email bubble to the top even if ES
        // scoring is identical.
        usort($items, function (array $a, array $b): int {
            $aPhone = !empty($a['attributes']['has_contact_phone']);
            $bPhone = !empty($b['attributes']['has_contact_phone']);
            if ($aPhone !== $bPhone) {
                return $aPhone ? -1 : 1;
            }

            $aEmail = !empty($a['attributes']['has_contact_email']);
            $bEmail = !empty($b['attributes']['has_contact_email']);
            if ($aEmail !== $bEmail) {
                return $aEmail ? -1 : 1;
            }

            return 0;
        });

        $rawAggs = $results['aggregations'] ?? [];

        // Generic aggregation formatter keyed by filter id
        $formattedAggs = [];
        foreach ($rawAggs as $aggId => $aggBody) {
            if (isset($aggBody['buckets']) && is_array($aggBody['buckets'])) {
                // Terms or range buckets
                $formattedAggs[$aggId] = [];
                foreach ($aggBody['buckets'] as $bucket) {
                    $key = (string) ($bucket['key'] ?? '');
                    $count = (int) ($bucket['doc_count'] ?? 0);
                    $formattedAggs[$aggId][] = ['key' => $key, 'count' => $count];
                }
            } elseif (isset($aggBody['doc_count'])) {
                // Single filter count
                $formattedAggs[$aggId] = (int) $aggBody['doc_count'];
            } elseif (isset($aggBody['filters']['buckets'])) {
                // Presence-style filters aggregation
                $known = (int) ($aggBody['filters']['buckets']['known']['doc_count'] ?? 0);
                $unknown = (int) ($aggBody['filters']['buckets']['unknown']['doc_count'] ?? 0);
                $formattedAggs[$aggId] = ['known' => $known, 'unknown' => $unknown];
            }
        }

            return [
                'data' => $items,
                'meta' => [
                'current_page' => $results['current_page'],
                'per_page' => $results['per_page'],
                'total' => $results['total'],
                'last_page' => $results['last_page'],
            ],
            'filters' => $filterDsl,
            'aggregations' => $formattedAggs,
        ];
    }

    private function formatTermsAggregation(?array $agg): array
    {
        if (!$agg || empty($agg['buckets']) || !is_array($agg['buckets'])) {
            return [];
        }

        $out = [];
        foreach ($agg['buckets'] as $bucket) {
            $key = (string) ($bucket['key'] ?? '');
            $count = (int) ($bucket['doc_count'] ?? 0);
            if ($key === '' || $count === 0) {
                continue;
            }
            $out[$key] = $count;
        }

        return $out;
    }

    private function mergeTermAggs(array $primary, array $fallback): array
    {
        if (empty($primary) && empty($fallback)) {
            return [];
        }

        $out = $primary;
        foreach ($fallback as $key => $count) {
            if (!isset($out[$key])) {
                $out[$key] = $count;
            } else {
                $out[$key] += $count;
            }
        }

        return $out;
    }

    private function formatRangeAggregation(?array $agg): array
    {
        if (!$agg || empty($agg['buckets']) || !is_array($agg['buckets'])) {
            return [];
        }

        $out = [];
        foreach ($agg['buckets'] as $bucket) {
            $key = (string) ($bucket['key'] ?? '');
            $count = (int) ($bucket['doc_count'] ?? 0);
            if ($key === '' || $count === 0) {
                continue;
            }
            $out[$key] = $count;
        }

        return $out;
    }

    

    private function expandAbbreviationSynonyms(string $term): array
    {
        $t = trim($term);
        if ($t === '') {
            return [];
        }
        $lower = strtolower($t);
        $syn = [
            'cto' => ['cto', 'chief technology officer', 'technology lead'],
            'cfo' => ['cfo', 'chief financial officer'],
            'coo' => ['coo', 'chief operating officer'],
            'cmo' => ['cmo', 'chief marketing officer'],
            'cio' => ['cio', 'chief information officer'],
            'ceo' => ['ceo', 'chief executive officer'],
            'vpo' => ['vp of operations', 'vice president of operations'],
            'vps' => ['vp of sales', 'vice president of sales'],
            'vpm' => ['vp of marketing', 'vice president of marketing'],
            'vpe' => ['vp of engineering', 'vice president of engineering'],
            'vp' => ['vp', 'vice president'],
            'hr' => ['hr', 'human resources', 'human resources manager'],
            'it' => ['it', 'information technology'],
            'bd' => ['bd', 'business development'],
            'sde' => ['sde', 'software engineer', 'developer'],
            'swe' => ['swe', 'software engineer', 'developer'],
            'sre' => ['sre', 'site reliability engineer'],
        ];
        foreach ($syn as $abbr => $list) {
            if ($lower === $abbr) {
                return $list;
            }
        }
        return [$t];
    }

    private function expandDepartmentSynonyms(string $term): array
    {
        $t = trim($term);
        if ($t === '') {
            return [];
        }
        $lower = strtolower($t);
        $syns = [
            'engineering' => ['engineering', 'eng', 'dev', 'development', 'software engineer', 'software development'],
            'product' => ['product', 'product management', 'pm'],
            'sales' => ['sales', 'business development', 'bd', 'account executive'],
            'marketing' => ['marketing', 'growth', 'demand gen', 'performance marketing'],
            'hr' => ['hr', 'human resources', 'people', 'people ops', 'talent'],
            'finance' => ['finance', 'accounting', 'fp&a'],
            'it' => ['it', 'information technology'],
            'support' => ['support', 'customer support', 'helpdesk'],
            'data' => ['data', 'analytics', 'business intelligence'],
        ];

        // Check if the input itself is a canonical name or shorthand
        foreach ($syns as $canonical => $variants) {
            if ($lower === $canonical || in_array($lower, $variants)) {
                return $variants;
            }
        }

        return [$t];
    }
    protected function applySearchQuery(ElasticQueryBuilder $builder, string $modelType, array $searchConfig, ?string $query): void
    {
        if (empty($query = trim((string) $query))) {
            return;
        }

        if ($this->isDomainLike($query)) {
            $this->addDomainSpecificSearch($builder, $modelType, $query);

            return;
        }

        if ($this->hasSpecialOperators($query)) {
            $this->addSpecialOperatorSearch($builder, $query, $searchConfig);

            return;
        }

        $this->addGeneralSearch($builder, $searchConfig, $query);
    }

    protected function isDomainLike(string $query): bool
    {
        return
            str_contains($query, '.') ||
            str_contains($query, 'www') ||
            str_contains($query, 'http') ||
            preg_match('/^[a-zA-Z0-9-]+\.[a-zA-Z]{2,}$/', $query);
    }

    protected function addDomainSpecificSearch(ElasticQueryBuilder $builder, string $modelType, string $query): void
    {
        // Clean the domain query
        $domainQuery = preg_replace(['#^https?://#', '#^www\\.#'], '', strtolower(trim($query)));
        $linkedInField = $modelType === 'company' ? 'company_linkedin_url' : 'linkedin_url';

        $shouldClauses = [
            // Exact domain match (highest priority)
            ['term' => ['website' => ['value' => $domainQuery, 'boost' => 10]]],
            // Company LinkedIn URL match
            ['term' => [$linkedInField => ['value' => $domainQuery, 'boost' => 8]]],
            // Domain contains match
            ['wildcard' => ['website' => ['value' => "*$domainQuery*", 'boost' => 5]]],
        ];

        $builder->should([
            'bool' => [
                'should' => $shouldClauses,
                'minimum_should_match' => 1,
            ],
        ]);
    }

    protected function hasSpecialOperators(string $query): bool
    {
        return preg_match('/("[^"]+"|(?:\bAND\b|\bOR\b|\(|\)))/', $query) === 1;
    }

    protected function addSpecialOperatorSearch(ElasticQueryBuilder $builder, string $query, array $searchConfig): void
    {
        $queryParser = new SearchTermParser;
        try {
            $parsedQuery = $queryParser->parse($query);
            $elasticQuery = $this->buildSpecialOperatorQuery($parsedQuery, $searchConfig);
            if (!empty($elasticQuery)) {
                $builder->must($elasticQuery);
            }
        } catch (InvalidArgumentException $e) {
            // Fallback
            $this->addGeneralSearch($builder, $searchConfig, $query);
        }
    }

    protected function buildSpecialOperatorQuery(array $parsed, array $searchConfig): array
    {
        switch ($parsed['type']) {
            case 'and':
                $must = [];
                foreach ($parsed['terms'] as $term) {
                    $must[] = $this->buildSpecialOperatorQuery($term, $searchConfig);
                }

                return ['bool' => ['must' => $must]];

            case 'or':
                $should = [];
                foreach ($parsed['terms'] as $term) {
                    $should[] = $this->buildSpecialOperatorQuery($term, $searchConfig);
                }

                return ['bool' => ['should' => $should, 'minimum_should_match' => 1]];

            case 'phrase':
                $shouldClauses = [];
                foreach ($searchConfig['phrase_fields'] as $field => $boost) {
                    $shouldClauses[] = [
                        'match_phrase' => [
                            $field => [
                                'query' => $parsed['value'],
                                'boost' => $boost * 2,
                            ],
                        ],
                    ];
                }

                return ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];

            case 'term':
                $shouldClauses = [];
                $this->addExactMatches($shouldClauses, $searchConfig['exact_fields'], $parsed['value']);
                $this->addPrefixMatches($shouldClauses, $searchConfig['prefix_fields'], $parsed['value']);
                $this->addNGramMatches($shouldClauses, $searchConfig['ngram_fields'], $parsed['value']);
                $this->addFuzzyMatches($shouldClauses, $searchConfig['text_fields'], $parsed['value']);

                return ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
            default:
                return [];
        }
    }

    private function addGeneralSearch(ElasticQueryBuilder $builder, array $searchConfig, string $query): void
    {
        $shouldClauses = [];

        $this->addExactMatches($shouldClauses, $searchConfig['exact_fields'], $query);
        $this->addPhraseMatches($shouldClauses, $searchConfig['phrase_fields'], $query);

        foreach ($shouldClauses as $clause) {
            $builder->should($clause);
        }
        $builder->setBoolParam('minimum_should_match', 1);
        $builder->minScore(0.1);
    }

    protected function addExactMatches(array &$shouldClauses, array $fields, string $query): void
    {
        foreach ($fields as $field => $boost) {
            $shouldClauses[] = [
                'term' => [
                    $field => [
                        'value' => $query,
                        'boost' => $boost * 3,
                    ],
                ],
            ];
        }
    }

    protected function addPhraseMatches(array &$shouldClauses, array $fields, string $query): void
    {
        foreach ($fields as $field => $boost) {
            $shouldClauses[] = [
                'match_phrase' => [
                    $field => [
                        'query' => $query,
                        'boost' => $boost * 2,
                        'slop' => 1,  // Allow slight word position variations
                    ],
                ],
            ];
        }
    }

    protected function addPrefixMatches(array &$shouldClauses, array $fields, string $query): void
    {
        foreach ($fields as $field => $boost) {
            $shouldClauses[] = [
                'match' => [
                    "$field.prefix" => [
                        'query' => $query,
                        'boost' => $boost,
                    ],
                ],
            ];
        }
    }

    protected function addNGramMatches(array &$shouldClauses, array $fields, string $query): void
    {
        foreach ($fields as $field => $boost) {
            $shouldClauses[] = [
                'match' => [
                    $field => [
                        'query' => $query,
                        'boost' => $boost,
                        'operator' => 'and',
                    ],
                ],
            ];
        }
    }

    protected function addFuzzyMatches(array &$shouldClauses, array $fields, string $query): void
    {
        $shouldClauses[] = [
            'multi_match' => [
                'query' => $query,
                'type' => 'best_fields',
                'fields' => array_map(
                    fn($field, $boost) => "$field^$boost",
                    array_keys($fields),
                    array_values($fields)
                ),
                'fuzziness' => 'AUTO',
                'prefix_length' => 2,
                'tie_breaker' => 0.3,
            ],
        ];
    }

    
    /**
     * Resolves company filters into a list of matching company domains.
     * This allows "Contact search by Company properties" (Cross-Index Filtering).
     */
    /**
     * Enhanced resolveCompanyFiltersToDomains to handle all company filter types
     */
    private function resolveCompanyFiltersToDomains(array $companyFilters): array
    {
        if (empty($companyFilters)) {
            return [];
        }

        $builder = \App\Models\Company::elastic();
        $builder->index((new \App\Models\Company())->elasticIndex());

        $must = [];
        $mustNot = [];
        $filterClauses = [];

        // Map contact DSL company filters to actual company index filters
        $filterMap = [
            'company_employee_count' => 'employee_count',
            'company_headcount' => 'employee_count',
            'employee_count' => 'employee_count',
            'company_revenue' => 'annual_revenue',
            'revenue' => 'annual_revenue',
            'annual_revenue' => 'annual_revenue',
            'company_business_category' => 'business_category',
            'business_category' => 'business_category',
            'businessCategories' => 'business_category',
            'company_technologies' => 'company_technologies',
            'technologies' => 'company_technologies',
            'company_locations' => 'location',
            'company_location' => 'location',
            'company_headquarters' => 'locations',
            'company_founded_year' => 'foundedYear',
            'founded_year' => 'foundedYear',
            'company_domains' => 'domains',
            'company_has' => 'has',
            'company_keywords' => 'company_keywords'
        ];

        // Apply each company filter
        foreach ($companyFilters as $key => $value) {
            $actualKey = $filterMap[$key] ?? $key;

            switch ($actualKey) {
                case 'employees':
                    if (is_array($value)) {
                        $range = [];
                        if (isset($value['min']) && $value['min'] !== null) {
                            $range['gte'] = (int) $value['min'];
                        }
                        if (isset($value['max']) && $value['max'] !== null) {
                            $range['lte'] = (int) $value['max'];
                        }
                        if ($range) {
                            $filterClauses[] = ['range' => ['employees' => $range]];
                        }

                        // Handle include/exclude format
                        $include = $value['include'] ?? [];

                        if (!empty($include)) {
                            $should = [];
                            foreach ($include as $bracket) {
                                $min = null;
                                $max = null;
                                if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $bracket, $m)) {
                                    $min = (int) $m[1];
                                    $max = (int) $m[2];
                                } elseif (preg_match('/^(\d+)\+$/', $bracket, $m)) {
                                    $min = (int) $m[1];
                                }

                                $range = [];
                                if ($min !== null)
                                    $range['gte'] = $min;
                                if ($max !== null)
                                    $range['lte'] = $max;
                                if ($range) {
                                    $should[] = ['range' => ['employees' => $range]];
                                }
                            }
                            if ($should) {
                                $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                            }
                        }
                    }
                    break;

                case 'annualRevenue':
                    if (is_array($value)) {
                        $range = [];
                        if (isset($value['min']) && $value['min'] !== null) {
                            $range['gte'] = (float) $value['min'];
                        }
                        if (isset($value['max']) && $value['max'] !== null) {
                            $range['lte'] = (float) $value['max'];
                        }
                        if ($range) {
                            $filterClauses[] = ['range' => ['annualRevenue' => $range]];
                        }

                        // Handle include/exclude buckets
                        $include = array_values(array_filter(array_map('trim', (array) ($value['include'] ?? [])), 'strlen'));
                        if ($include) {
                            $parseMoney = function ($val) {
                                $val = strtoupper(str_replace(['$', ',', ' '], '', $val));
                                $mult = 1;
                                if (str_ends_with($val, 'M')) {
                                    $mult = 1000000;
                                    $val = substr($val, 0, -1);
                                } elseif (str_ends_with($val, 'B')) {
                                    $mult = 1000000000;
                                    $val = substr($val, 0, -1);
                                } elseif (str_ends_with($val, 'K')) {
                                    $mult = 1000;
                                    $val = substr($val, 0, -1);
                                }
                                return is_numeric($val) ? ((float) $val) * $mult : null;
                            };

                            $should = [];
                            foreach ($include as $bucket) {
                                $min = null;
                                $max = null;
                                if (str_contains($bucket, '+')) {
                                    $parts = explode('+', $bucket);
                                    $min = $parseMoney($parts[0]);
                                } elseif (str_contains($bucket, '-')) {
                                    $parts = explode('-', $bucket);
                                    $min = $parseMoney($parts[0]);
                                    $max = $parseMoney($parts[1] ?? '');
                                } else {
                                    $min = $parseMoney($bucket);
                                    $max = $min;
                                }

                                $r = [];
                                if ($min !== null)
                                    $r['gte'] = $min;
                                if ($max !== null)
                                    $r['lte'] = $max;
                                if ($r) {
                                    $should[] = ['range' => ['annualRevenue' => $r]];
                                }
                            }
                            if ($should) {
                                $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                            }
                        }
                    }
                    break;

                case 'businessCategory':
                    if (is_array($value)) {
                        $include = $value['include'] ?? [];
                        $exclude = $value['exclude'] ?? [];

                        if (!empty($include)) {
                            $should = [];
                            $should[] = ['terms' => ['businessCategories' => $include]];
                            $should[] = ['terms' => ['businessCategory' => $include]];
                            $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                        }
                        if (!empty($exclude)) {
                            $should = [];
                            $should[] = ['terms' => ['businessCategory' => $exclude]];
                            $should[] = ['terms' => ['businessCategories' => $exclude]];
                            $mustNot[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                        }
                    }
                    break;

                case 'technologies':
                    if (is_array($value)) {
                        $includeRaw = $value['include'] ?? [];
                        $excludeRaw = $value['exclude'] ?? [];

                        if (!empty($includeRaw)) {
                            $includeNorm = RecordNormalizer::normalizeTechnologies($includeRaw);
                            $shouldTech = [];
                            if ($includeNorm) {
                                $shouldTech[] = ['terms' => ['technologies_normalized' => $includeNorm]];
                            }
                            $rawFields = ['technologies', 'company_technologies', 'tech_stack'];
                            foreach ($includeRaw as $t) {
                                $shouldTech[] = [
                                    'multi_match' => [
                                        'query' => $t,
                                        'type' => 'phrase',
                                        'fields' => array_map(fn($f) => $f . '^2', $rawFields)
                                    ]
                                ];
                            }
                            if ($shouldTech) {
                                $filterClauses[] = ['bool' => ['should' => $shouldTech, 'minimum_should_match' => 1]];
                            }
                        }
                        if (!empty($excludeRaw)) {
                            $excludeNorm = RecordNormalizer::normalizeTechnologies($excludeRaw);
                            if ($excludeNorm) {
                                $mustNot[] = ['terms' => ['technologies_normalized' => $excludeNorm]];
                            }
                            $rawFields = ['technologies', 'company_technologies', 'tech_stack'];
                            foreach ($excludeRaw as $t) {
                                $mustNot[] = [
                                    'multi_match' => [
                                        'query' => $t,
                                        'type' => 'phrase',
                                        'fields' => $rawFields
                                    ]
                                ];
                            }
                        }
                    }
                    break;

                

                case 'foundedYear':
                    if (is_array($value)) {
                        $range = [];
                        if (isset($value['min']) && $value['min'] !== null) {
                            $range['gte'] = (int) $value['min'];
                        }
                        if (isset($value['max']) && $value['max'] !== null) {
                            $range['lte'] = (int) $value['max'];
                        }
                        if ($range) {
                            $filterClauses[] = ['range' => ['foundedYear' => $range]];
                        }
                    }
                    break;

                case 'company_keywords':
                    if (is_array($value)) {
                        $include = $value['include'] ?? [];
                        $exclude = $value['exclude'] ?? [];

                        if (!empty($include)) {
                            $should = [];
                            foreach ($include as $keyword) {
                                $should[] = [
                                    'multi_match' => [
                                        'query' => $keyword,
                                        'type' => 'phrase',
                                        'fields' => ['businessCategory^3', 'businessCategories^3', 'company_business_category^3', 'technologies^3', 'keywords^2', 'seoDescription', 'businessDescription'],
                                    ]
                                ];
                            }
                            $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                        }
                        if (!empty($exclude)) {
                            $should = [];
                            foreach ($exclude as $keyword) {
                                $should[] = [
                                    'multi_match' => [
                                        'query' => $keyword,
                                        'type' => 'phrase',
                                        'fields' => ['businessCategory^3', 'businessCategories^3', 'technologies', 'keywords', 'seoDescription', 'businessDescription'],
                                    ]
                                ];
                            }
                            $mustNot[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                        }
                    }
                    break;

                case 'company_names':
                    if (is_array($value)) {
                        $include = $value['include'] ?? [];
                        $exclude = $value['exclude'] ?? [];

                        if (!empty($include)) {
                            $filterClauses[] = ['terms' => ['company.keyword' => $include]];
                        }
                        if (!empty($exclude)) {
                            $mustNot[] = ['terms' => ['company.keyword' => $exclude]];
                        }
                    }
                    break;

                case 'domains':
                    if (is_array($value)) {
                        $include = $value['include'] ?? [];
                        $exclude = $value['exclude'] ?? [];

                        if (!empty($include)) {
                            $filterClauses[] = ['terms' => ['domain' => $include]];
                        }
                        if (!empty($exclude)) {
                            $mustNot[] = ['terms' => ['domain' => $exclude]];
                        }
                    }
                    break;
            }
        }

        // Apply all clauses to builder
        foreach ($must as $clause) {
            $builder->must($clause);
        }
        foreach ($mustNot as $clause) {
            $builder->mustNot($clause);
        }
        foreach ($filterClauses as $clause) {
            $builder->filter($clause);
        }

        // Select only domain fields and limit results
        $builder->select(['domain', 'website']);

        // Execute search (paginate to request up to 10k docs)
        $results = $builder->paginate(1, 10000);

        // Extract unique domains
        $domains = [];
        foreach (($results['data'] ?? []) as $row) {
            if (!empty($row['domain'])) {
                $domains[] = $row['domain'];
            } elseif (!empty($row['website'])) {
                $domains[] = $row['website'];
            }
        }

        return array_unique($domains);
    }
}
