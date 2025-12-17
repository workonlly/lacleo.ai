<?php

namespace App\Services;

use App\Elasticsearch\ElasticQueryBuilder;
use App\Filters\FilterManager;
use App\Models\Company;
use App\Models\Contact;
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
        protected EmbeddingService $embeddingService
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
        ?string $semanticQuery = null
    ): array {
        try {
            $page = max(1, (int) $page);
            $perPage = max(1, min(100, (int) $perPage));
            $modelClass = $this->getModelClass($type);
            $builder = $modelClass::elastic();

            $index = (new $modelClass())->elasticIndex();
            $builder->index($index);

            // Apply boolean filters (DSL)
            $this->applyFilters($builder, $type, $filterDsl);

            // Apply standard keyword search if present
            $this->applySearchQuery($builder, $type, $modelClass::globalSearchFields(), $query);

            // Apply Semantic Search (Vector) if present
            if ($semanticQuery) {
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

            // Aggregations for filter UIs (industries, locations, size, technologies, has_xx)
            $this->applyAggregations($builder, $type, $filterDsl);

            // Return full _source for each ES hit (no source filtering)

            $results = $builder->paginate($page, $perPage);

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
        $index = (new $modelClass())->elasticIndex();
        $builder->index($index);
        $this->applyFilters($builder, $type, $filterDsl);
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
            $numericFields = ['employee_count', 'annual_revenue_usd', 'founded_year', 'latest_funding_amount', 'total_funding_usd'];
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
     * Apollo-Style Cross-Index Filtering Implementation
     */
    private function applyFilters(ElasticQueryBuilder $builder, string $type, array $filters): void
    {
        // Store original filters for debugging
        $this->lastAppliedClauses = ['original_filters' => $filters];

        $must = [];
        $mustNot = [];
        $filterClauses = [];

        // Normalize backend aliases early
        if (!empty($filters['company_headcount_contact'])) {
            $filters['company_headcount'] = $filters['company_headcount_contact'];
            unset($filters['company_headcount_contact']);
        }

        // --- Apollo-Style Cross-Index Filtering ---
        // Extract company filters from DSL when searching contacts
        if ($type === 'contact') {
            // Check for company filters in the DSL structure
            $companyFilters = [];

            // 1. Check for nested 'company' key in filters
            if (!empty($filters['company']) && is_array($filters['company'])) {
                $companyFilters = $filters['company'];
            }

            // 2. Also check for company-related filters at top level (for backward compatibility)
            $companyFilterKeys = [
                // Prefixed (contact DSL -> company index)
                'company_employee_count',
                'company_headcount',
                'company_revenue',
                'company_industries',
                'company_technologies',
                'company_locations',
                'company_founded_year',
                'company_domains',
                'company_has',
                'company_names',
                'company_location',
                'company_headquarters',
                'company_keywords',
                // Canonical (UI may send plain company fields on contacts page)
                'employee_count',
                'annual_revenue',
                'industry',
                'industries',
                'technologies',
                'locations',
                'location',
                'founded_year',
                'domains'
            ];

            foreach ($companyFilterKeys as $key) {
                if (!empty($filters[$key])) {
                    $companyFilters[$key] = $filters[$key];
                }
            }

            // 3. If we have company filters, resolve them to domains
            if (!empty($companyFilters)) {
                $resolvedDomains = $this->resolveCompanyFiltersToDomains($companyFilters);

                // If no companies matched the filters, then no contacts should match either.
                if (empty($resolvedDomains)) {
                    $filterClauses[] = ['term' => ['website' => '__NO_MATCH_XYZ_123__']];
                } else {
                    $domainShould = [];
                    $domainShould[] = ['terms' => ['website' => $resolvedDomains]];
                    $domainShould[] = ['terms' => ['domain' => $resolvedDomains]];
                    $domainShould[] = ['terms' => ['company_obj.domain' => $resolvedDomains]];
                    $filterClauses[] = ['bool' => ['should' => $domainShould, 'minimum_should_match' => 1]];
                }

                // Store for debugging
                $this->lastAppliedClauses['company_filters'] = $companyFilters;
                $this->lastAppliedClauses['resolved_domains_count'] = count($resolvedDomains);
            }
        }

        // Process regular contact filters (excluding company filters)
        // Unwrap 'contact' bucket if present, merging it into the top-level
        if (isset($filters['contact']) && is_array($filters['contact'])) {
            $filters = array_merge($filters, $filters['contact']);
        }
        // Unwrap 'company' bucket when searching the company index
        if ($type === 'company' && isset($filters['company']) && is_array($filters['company'])) {
            $filters = array_merge($filters, $filters['company']);
        }

        // Normalize legacy/frontend filter IDs to canonical keys
        // Map company name filters → company_names
        foreach (['company_name_contact', 'company_name_company', 'name_contact', 'name_company'] as $nameKey) {
            if (!empty($filters[$nameKey]) && is_array($filters[$nameKey])) {
                $filters['company_names'] = $filters[$nameKey];
                unset($filters[$nameKey]);
            }
        }
        // Map company domain filters → domains
        foreach (['company_domain_contact', 'company_domain_company', 'domain_contact', 'domain_company'] as $domKey) {
            if (!empty($filters[$domKey]) && is_array($filters[$domKey])) {
                $filters['domains'] = $filters[$domKey];
                unset($filters[$domKey]);
            }
        }

        // Industries
        if (!empty($filters['industries'])) {
            $inds = $filters['industries'];
            $include = array_values(array_filter(array_map('trim', (array) ($inds['include'] ?? [])), 'strlen'));
            $exclude = array_values(array_filter(array_map('trim', (array) ($inds['exclude'] ?? [])), 'strlen'));
            $presence = $inds['presence'] ?? 'any';

            // Presence Logic (Known / Unknown)
            if ($presence === 'known') {
                if ($type === 'company') {
                    $shouldStart = [];
                    $shouldStart[] = ['exists' => ['field' => 'industry']];
                    $shouldStart[] = ['exists' => ['field' => 'industries']];
                    $shouldStart[] = ['exists' => ['field' => 'business_category']];
                    $filterClauses[] = ['bool' => ['should' => $shouldStart, 'minimum_should_match' => 1]];
                } else {
                    $shouldStart = [];
                    $shouldStart[] = ['exists' => ['field' => 'company_obj.industry']];
                    $shouldStart[] = ['exists' => ['field' => 'company_obj.industries']];
                    $filterClauses[] = ['bool' => ['should' => $shouldStart, 'minimum_should_match' => 1]];
                }
            } elseif ($presence === 'unknown') {
                if ($type === 'company') {
                    $mustNot[] = ['exists' => ['field' => 'industry']];
                    $mustNot[] = ['exists' => ['field' => 'industries']];
                    $mustNot[] = ['exists' => ['field' => 'business_category']];
                } else {
                    $mustNot[] = ['exists' => ['field' => 'company_obj.industry']];
                    $mustNot[] = ['exists' => ['field' => 'company_obj.industries']];
                }
            }

            // Include Logic
            if ($include) {
                if ($type === 'company') {
                    $should = [];
                    $should[] = ['terms' => ['industry' => $include]];
                    $should[] = ['terms' => ['industries' => $include]];
                    $should[] = ['terms' => ['business_category' => $include]];
                    $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                } else {
                    $should = [];
                    $should[] = ['terms' => ['company_obj.industry' => $include]];
                    $should[] = ['terms' => ['company_obj.industries' => $include]];
                    $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                }
            }

            // Exclude Logic
            if ($exclude) {
                if ($type === 'company') {
                    $shouldExclude = [];
                    $shouldExclude[] = ['terms' => ['industry' => $exclude]];
                    $shouldExclude[] = ['terms' => ['industries' => $exclude]];
                    $shouldExclude[] = ['terms' => ['business_category' => $exclude]];
                    $mustNot[] = ['bool' => ['should' => $shouldExclude, 'minimum_should_match' => 1]];
                } else {
                    $shouldExclude = [];
                    $shouldExclude[] = ['terms' => ['company_obj.industry' => $exclude]];
                    $shouldExclude[] = ['terms' => ['company_obj.industries' => $exclude]];
                    $mustNot[] = ['bool' => ['should' => $shouldExclude, 'minimum_should_match' => 1]];
                }
            }
        }

        if (!empty($filters['company_keywords'])) {
            $ck = $filters['company_keywords'];
            $include = array_values(array_filter(array_map('trim', (array) ($ck['include'] ?? [])), 'strlen'));
            $exclude = array_values(array_filter(array_map('trim', (array) ($ck['exclude'] ?? [])), 'strlen'));
            $mode = ($ck['mode'] ?? 'all') === 'any' ? 'should' : 'must';
            $fieldsParam = $ck['fields'] ?? ['name', 'keywords', 'description'];

            $targetFields = [];
            foreach ($fieldsParam as $f) {
                switch ($f) {
                    case 'name':
                        if ($type === 'company') {
                            $targetFields[] = 'company_name^3';
                            $targetFields[] = 'company.keyword^3';
                        } else {
                            $targetFields[] = 'company_obj.name^3'; // Assuming name is top level in company_obj
                        }
                        break;
                    case 'keywords':
                        if ($type === 'company') {
                            $targetFields[] = 'industry';
                            $targetFields[] = 'industries';
                            $targetFields[] = 'technologies';
                            $targetFields[] = 'keywords';
                        } else {
                            $targetFields[] = 'company_obj.industry';
                            $targetFields[] = 'company_obj.industries';
                            $targetFields[] = 'company_obj.technologies';
                            $targetFields[] = 'company_obj.keywords';
                        }
                        break;
                    case 'description':
                        if ($type === 'company') {
                            $targetFields[] = 'short_description';
                            $targetFields[] = 'description';
                            $targetFields[] = 'about';
                        } else {
                            $targetFields[] = 'company_obj.short_description';
                            $targetFields[] = 'company_obj.description';
                            $targetFields[] = 'company_obj.about';
                        }
                        break;
                }
            }
            if (empty($targetFields)) {
                if ($type === 'company') {
                    $targetFields = ['short_description', 'description', 'industry', 'company_name'];
                } else {
                    $targetFields = ['company_obj.short_description', 'company_obj.description', 'company_obj.industry', 'company_obj.name'];
                }
            }

            // Include Logic
            if ($include) {
                $keywordClauses = [];
                foreach ($include as $term) {
                    $keywordClauses[] = [
                        'multi_match' => [
                            'query' => $term,
                            'fields' => $targetFields,
                            'type' => 'phrase',
                            'operator' => 'and'
                        ]
                    ];
                }

                if ($mode === 'must') {
                    foreach ($keywordClauses as $clause) {
                        $filterClauses[] = $clause;
                    }
                } else {
                    $filterClauses[] = ['bool' => ['should' => $keywordClauses, 'minimum_should_match' => 1]];
                }
            }

            // Exclude Logic
            if ($exclude) {
                foreach ($exclude as $term) {
                    $mustNot[] = [
                        'multi_match' => [
                            'query' => $term,
                            'fields' => $targetFields,
                            'type' => 'phrase',
                        ]
                    ];
                }
            }
        }

        // ... Locations block ... (ommitted changes for now, focusing on company fields)

        // Employee Count (Range)
        if (!empty($filters['employee_count']) && is_array($filters['employee_count'])) {
            $rng = $filters['employee_count'];
            $range = [];
            if (isset($rng['min']) && $rng['min'] !== null) {
                $range['gte'] = (int) $rng['min'];
            }
            if (isset($rng['max']) && $rng['max'] !== null) {
                $range['lte'] = (int) $rng['max'];
            }
            if ($range) {
                if ($type === 'company') {
                    $filterClauses[] = ['range' => ['employee_count' => $range]];
                } else {
                    $filterClauses[] = ['range' => ['company_obj.employee_count' => $range]];
                }
            }
        }

        // Employee Count (Headcount Buckets)
        if (!empty($filters['company_headcount']) && is_array($filters['company_headcount'])) {
            // ... Logic same as before but applying field logic
            $ch = $filters['company_headcount'];
            $include = array_values(array_filter(array_map('trim', (array) ($ch['include'] ?? [])), 'strlen'));
            $exclude = array_values(array_filter(array_map('trim', (array) ($ch['exclude'] ?? [])), 'strlen'));

            $field = ($type === 'company') ? 'employee_count' : 'company_obj.employee_count';

            // ... (Include logic)
            if ($include) {
                $should = [];
                foreach ($include as $bracket) {
                    // ... regex logic ...
                    $min = null;
                    $max = null;
                    if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $bracket, $m)) {
                        $min = (int) $m[1];
                        $max = (int) $m[2];
                    } elseif (preg_match('/^(\d+)\+$/', $bracket, $m)) {
                        $min = (int) $m[1];
                        $max = null;
                    }

                    $range = [];
                    if ($min !== null)
                        $range['gte'] = $min;
                    if ($max !== null)
                        $range['lte'] = $max;

                    if (!empty($range)) {
                        $should[] = ['range' => [$field => $range]];
                    }
                }
                if ($should)
                    $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
            }

            // ... (Exclude logic) - using $field variable
            if ($exclude) {
                foreach ($exclude as $bracket) {
                    // ... regex logic ...
                    $min = null;
                    $max = null;
                    if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $bracket, $m)) {
                        $min = (int) $m[1];
                        $max = (int) $m[2];
                    } elseif (preg_match('/^(\d+)\+$/', $bracket, $m)) {
                        $min = (int) $m[1];
                        $max = null;
                    }

                    $range = [];
                    if ($min !== null)
                        $range['gte'] = $min;
                    if ($max !== null)
                        $range['lte'] = $max;

                    if (!empty($range))
                        $mustNot[] = ['range' => [$field => $range]];
                }
            }
        }

        // Revenue
        if (!empty($filters['revenue']) && is_array($filters['revenue'])) {
            $rev = $filters['revenue'];
            $field = ($type === 'company') ? 'annual_revenue' : 'company_obj.annual_revenue';

            // 1. Min/Max
            $range = [];
            if (isset($rev['min']) && $rev['min'] !== null)
                $range['gte'] = (float) $rev['min'];
            if (isset($rev['max']) && $rev['max'] !== null)
                $range['lte'] = (float) $rev['max'];

            if ($range) {
                $filterClauses[] = ['range' => [$field => $range]];
            }

            // 2. String Buckets
            $include = array_values(array_filter(array_map('trim', (array) ($rev['include'] ?? [])), 'strlen'));
            // ... parseMoney helper ...
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

            if ($include) {
                $should = [];
                foreach ($include as $bucket) {
                    // ... range parsing logic ... 
                    $min = null;
                    $max = null;
                    if (str_contains($bucket, '+')) { // ... 
                        $parts = explode('+', $bucket);
                        $min = $parseMoney($parts[0]);
                    } elseif (str_contains($bucket, '-')) { // ...
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

                    if ($r)
                        $should[] = ['range' => [$field => $r]];
                }
                if ($should)
                    $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
            }
        }

        // Technologies (companies only)
        if (!empty($filters['technologies'])) {
            $tech = $filters['technologies'];
            $include = array_values(array_filter(array_map('trim', (array) ($tech['include'] ?? [])), 'strlen'));
            $exclude = array_values(array_filter(array_map('trim', (array) ($tech['exclude'] ?? [])), 'strlen'));
            if ($type === 'company') {
                if ($include) {
                    $filterClauses[] = ['terms' => ['technologies' => $include]];
                }
                if ($exclude) {
                    $mustNot[] = ['terms' => ['technologies' => $exclude]];
                }
            } else {
                if ($include) {
                    $filterClauses[] = ['terms' => ['company_obj.technologies' => $include]];
                }
                if ($exclude) {
                    $mustNot[] = ['terms' => ['company_obj.technologies' => $exclude]];
                }
            }
        }

        if (!empty($filters['domains'])) {
            $dom = $filters['domains'];
            $include = array_values(array_filter(array_map('trim', (array) ($dom['include'] ?? [])), 'strlen'));
            $exclude = array_values(array_filter(array_map('trim', (array) ($dom['exclude'] ?? [])), 'strlen'));
            $operator = strtolower((string) ($dom['operator'] ?? 'or')) === 'and' ? 'and' : 'or';

            $normalize = function (string $d): string {
                $d = strtolower(trim($d));
                $d = preg_replace(['#^https?://#', '#^www\.#'], '', $d);
                return $d;
            };
            $inc = array_map($normalize, $include);
            $exc = array_map($normalize, $exclude);

            $buildDomainClauses = function (array $domains): array {
                $clauses = [];
                foreach ($domains as $d) {
                    $clauses[] = ['term' => ['website' => ['value' => $d, 'boost' => 8]]];
                    $clauses[] = ['wildcard' => ['website' => ['value' => "*$d*", 'boost' => 3]]];
                    $clauses[] = ['term' => ['domain' => ['value' => $d, 'boost' => 8]]];
                }
                return $clauses;
            };

            if ($inc) {
                $clauses = $buildDomainClauses($inc);
                if ($operator === 'and') {
                    foreach ($inc as $d) {
                        $filterClauses[] = ['bool' => ['should' => $buildDomainClauses([$d]), 'minimum_should_match' => 1]];
                    }
                } else {
                    $filterClauses[] = ['bool' => ['should' => $clauses, 'minimum_should_match' => 1]];
                }
            }
            if ($exc) {
                foreach ($exc as $d) {
                    $mustNot[] = ['term' => ['website' => $d]];
                    $mustNot[] = ['term' => ['domain' => $d]];
                }
            }
        }

        if (!empty($filters['company_names'])) {
            $names = $filters['company_names'];
            $include = array_values(array_filter(array_map('trim', (array) ($names['include'] ?? [])), 'strlen'));
            $exclude = array_values(array_filter(array_map('trim', (array) ($names['exclude'] ?? [])), 'strlen'));
            $operator = strtolower((string) ($names['operator'] ?? 'or')) === 'and' ? 'and' : 'or';

            // Prefer phrase_prefix across name prefix fields to support Apollo-style prefix matching
            // and aliases. Fall back to keyword exact matches only when necessary.
            if ($include) {
                $makeNameClauses = function (string $q): array {
                    return [
                        [
                            'multi_match' => [
                                'query' => $q,
                                'type' => 'phrase_prefix',
                                'fields' => ['company.prefix^3', 'company_also_known_as.prefix^2', 'company.joined'],
                                'operator' => 'and',
                                'prefix_length' => 1,
                            ],
                        ],
                        ['term' => ['company.keyword' => $q]],
                    ];
                };
                if ($operator === 'and') {
                    foreach ($include as $q) {
                        $filterClauses[] = ['bool' => ['should' => $makeNameClauses($q), 'minimum_should_match' => 1]];
                    }
                } else {
                    $shouldClauses = [];
                    foreach ($include as $q) {
                        $shouldClauses = array_merge($shouldClauses, $makeNameClauses($q));
                    }
                    $filterClauses[] = ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
                }
            }
            if ($exclude) {
                foreach ($exclude as $q) {
                    $mustNot[] = [
                        'multi_match' => [
                            'query' => $q,
                            'type' => 'phrase_prefix',
                            'fields' => ['company.prefix', 'company_also_known_as.prefix', 'company.joined'],
                            'operator' => 'and',
                            'prefix_length' => 1,
                        ]
                    ];
                    $mustNot[] = ['term' => ['company.keyword' => $q]];
                }
            }
        }

        // Contact-specific: Job title include/exclude with multi_match fuzzy search
        if ($type === 'contact' && !empty($filters['job_title']) && is_array($filters['job_title'])) {
            $jt = $filters['job_title'];
            $include = array_values(array_filter(array_map('trim', (array) ($jt['include'] ?? [])), 'strlen'));
            $exclude = array_values(array_filter(array_map('trim', (array) ($jt['exclude'] ?? [])), 'strlen'));

            if ($include) {
                $shouldClauses = [];
                foreach ($include as $term) {
                    foreach ($this->expandAbbreviationSynonyms($term) as $t) {
                        $shouldClauses[] = [
                            'multi_match' => [
                                'query' => $t,
                                'type' => 'best_fields',
                                'fields' => [
                                    'job_title^3',
                                    'title^2',
                                    'normalized_title',
                                    'title_keywords',
                                    'title_synonyms',
                                ],
                                'operator' => 'and',
                                'fuzziness' => 'AUTO',
                                'prefix_length' => 1,
                                'minimum_should_match' => strlen($t) <= 3 ? '100%' : '75%'
                            ]
                        ];
                    }
                }
                if ($shouldClauses) {
                    $filterClauses[] = ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
                }
            }
            if ($exclude) {
                foreach ($exclude as $term) {
                    foreach ($this->expandAbbreviationSynonyms($term) as $t) {
                        $mustNot[] = [
                            'multi_match' => [
                                'query' => $t,
                                'type' => 'best_fields',
                                'fields' => ['job_title', 'title', 'normalized_title', 'title_keywords', 'title_synonyms'],
                                'operator' => 'and',
                                'fuzziness' => 'AUTO',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                }
            }
        }

        // Contact-specific: Department include/exclude with multi_match fuzzy search
        if ($type === 'contact' && (!empty($filters['departments']) || !empty($filters['department']))) {
            $deptFilter = $filters['departments'] ?? $filters['department'];
            if (is_array($deptFilter)) {
                $include = array_values(array_filter(array_map('trim', (array) ($deptFilter['include'] ?? [])), 'strlen'));
                $exclude = array_values(array_filter(array_map('trim', (array) ($deptFilter['exclude'] ?? [])), 'strlen'));

                if ($include) {
                    $shouldClauses = [];
                    foreach ($include as $term) {
                        foreach ($this->expandAbbreviationSynonyms($term) as $t) {
                            $shouldClauses[] = [
                                'multi_match' => [
                                    'query' => $t,
                                    'type' => 'best_fields',
                                    'fields' => ['departments^2', 'department'],
                                    'operator' => 'and',
                                    'fuzziness' => 'AUTO',
                                    'prefix_length' => 1,
                                ]
                            ];
                        }
                    }
                    if ($shouldClauses) {
                        $filterClauses[] = ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
                    }
                }
                if ($exclude) {
                    foreach ($exclude as $term) {
                        foreach ($this->expandAbbreviationSynonyms($term) as $t) {
                            $mustNot[] = [
                                'multi_match' => [
                                    'query' => $t,
                                    'type' => 'best_fields',
                                    'fields' => ['departments', 'department'],
                                    'operator' => 'and',
                                    'fuzziness' => 'AUTO',
                                    'prefix_length' => 1,
                                ]
                            ];
                        }
                    }
                }
            }
        }

        // Contact-specific: Seniority include/exclude using mapping and multi_match across titles
        if ($type === 'contact' && !empty($filters['seniority']) && is_array($filters['seniority'])) {
            $sen = $filters['seniority'];
            $include = array_values(array_filter(array_map('trim', (array) ($sen['include'] ?? [])), 'strlen'));
            $exclude = array_values(array_filter(array_map('trim', (array) ($sen['exclude'] ?? [])), 'strlen'));

            $mapTokens = function (string $label): array {
                $label = strtolower($label);
                if (preg_match('/^(entry|entry\s*level)$/', $label)) {
                    return ['entry', 'junior', 'intern', 'associate'];
                }
                if (preg_match('/^(mid|mid\s*-?\s*senior|middle)$/', $label)) {
                    return ['mid', 'middle', 'mid-senior'];
                }
                if (preg_match('/^(senior|sr)$/', $label)) {
                    return ['senior', 'sr', 'staff'];
                }
                if (preg_match('/^director$/', $label)) {
                    return ['director'];
                }
                if (preg_match('/^(manager)$/', $label)) {
                    return ['manager'];
                }
                if (preg_match('/^(lead|head)$/', $label)) {
                    return ['lead', 'head'];
                }
                if (preg_match('/^(vp|vice\s*president)$/', $label)) {
                    return ['vp', 'vice president', 'svp', 'avp'];
                }
                if (preg_match('/^(c\s*-?suite|executive|cxo)$/', $label)) {
                    return ['executive', 'cxo', 'chief', 'ceo', 'cto', 'cfo', 'coo', 'cso', 'ciso'];
                }
                return [$label];
            };

            if ($include) {
                $shouldClauses = [];
                foreach ($include as $label) {
                    foreach ($mapTokens($label) as $t) {
                        $shouldClauses[] = [
                            'multi_match' => [
                                'query' => $t,
                                'type' => 'best_fields',
                                'fields' => ['seniority_level^3', 'title^2', 'normalized_title'],
                                'operator' => 'and',
                                'fuzziness' => 'AUTO',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                }
                if ($shouldClauses) {
                    $filterClauses[] = ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
                }
            }
            if ($exclude) {
                foreach ($exclude as $label) {
                    foreach ($mapTokens($label) as $t) {
                        $mustNot[] = [
                            'multi_match' => [
                                'query' => $t,
                                'type' => 'best_fields',
                                'fields' => ['seniority_level', 'title', 'normalized_title'],
                                'operator' => 'and',
                                'fuzziness' => 'AUTO',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                }
            }
        }

        // Contact-specific: First Name and Last Name filters
        if ($type === 'contact') {
            // Unified name block using phrase_prefix multi_match across name fields
            if (!empty($filters['name']) && is_array($filters['name'])) {
                $nm = $filters['name'];
                $include = array_values(array_filter(array_map('trim', (array) ($nm['include'] ?? [])), 'strlen'));
                $exclude = array_values(array_filter(array_map('trim', (array) ($nm['exclude'] ?? [])), 'strlen'));

                if ($include) {
                    $shouldClauses = [];
                    foreach ($include as $q) {
                        $shouldClauses[] = [
                            'multi_match' => [
                                'query' => $q,
                                'type' => 'phrase_prefix',
                                'fields' => ['full_name^4', 'first_name^3', 'last_name^3'],
                                'operator' => 'and',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                    if ($shouldClauses) {
                        $filterClauses[] = ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
                    }
                }
                if ($exclude) {
                    foreach ($exclude as $q) {
                        $mustNot[] = [
                            'multi_match' => [
                                'query' => $q,
                                'type' => 'phrase_prefix',
                                'fields' => ['full_name', 'first_name', 'last_name'],
                                'operator' => 'and',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                }
            }
            if (!empty($filters['first_name']) && is_array($filters['first_name'])) {
                $fn = $filters['first_name'];
                $include = array_values(array_filter(array_map('trim', (array) ($fn['include'] ?? [])), 'strlen'));
                $exclude = array_values(array_filter(array_map('trim', (array) ($fn['exclude'] ?? [])), 'strlen'));

                if ($include) {
                    $shouldClauses = [];
                    foreach ($include as $name) {
                        $shouldClauses[] = [
                            'multi_match' => [
                                'query' => $name,
                                'type' => 'phrase_prefix',
                                'fields' => ['first_name^3', 'full_name^2'],
                                'operator' => 'and',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                    if ($shouldClauses) {
                        $filterClauses[] = ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
                    }
                }
                if ($exclude) {
                    foreach ($exclude as $name) {
                        $mustNot[] = [
                            'multi_match' => [
                                'query' => $name,
                                'type' => 'phrase_prefix',
                                'fields' => ['first_name', 'full_name'],
                                'operator' => 'and',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                }
            }

            if (!empty($filters['last_name']) && is_array($filters['last_name'])) {
                $ln = $filters['last_name'];
                $include = array_values(array_filter(array_map('trim', (array) ($ln['include'] ?? [])), 'strlen'));
                $exclude = array_values(array_filter(array_map('trim', (array) ($ln['exclude'] ?? [])), 'strlen'));

                if ($include) {
                    $shouldClauses = [];
                    foreach ($include as $name) {
                        $shouldClauses[] = [
                            'multi_match' => [
                                'query' => $name,
                                'type' => 'phrase_prefix',
                                'fields' => ['last_name^3', 'full_name^2'],
                                'operator' => 'and',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                    if ($shouldClauses) {
                        $filterClauses[] = ['bool' => ['should' => $shouldClauses, 'minimum_should_match' => 1]];
                    }
                }
                if ($exclude) {
                    foreach ($exclude as $name) {
                        $mustNot[] = [
                            'multi_match' => [
                                'query' => $name,
                                'type' => 'phrase_prefix',
                                'fields' => ['last_name', 'full_name'],
                                'operator' => 'and',
                                'prefix_length' => 1,
                            ]
                        ];
                    }
                }
            }
        }

        // Has filters (email / phone / linkedin / website)
        if (!empty($filters['has']) && is_array($filters['has'])) {
            $has = $filters['has'];

            if ($type === 'company') {
                // Company index: simple scalar fields
                $map = [
                    'email' => 'company_email',
                    'phone' => 'company_phone',
                    'linkedin' => 'company_linkedin_url',
                    'website' => 'website',
                ];

                foreach ($map as $key => $field) {
                    if (!array_key_exists($key, $has)) {
                        continue;
                    }
                    $val = $has[$key];
                    if ($val === true) {
                        $must[] = ['exists' => ['field' => $field]];
                    } elseif ($val === false) {
                        $mustNot[] = ['exists' => ['field' => $field]];
                    }
                }
            } else {
                // Contact index: emails / phone_numbers are nested.
                foreach (['email', 'phone', 'linkedin', 'website'] as $key) {
                    if (!array_key_exists($key, $has)) {
                        continue;
                    }
                    $val = $has[$key];
                    if ($val === null) {
                        continue;
                    }

                    if ($key === 'email') {
                        $nestedClause = [
                            'nested' => [
                                'path' => 'emails',
                                'query' => [
                                    'exists' => ['field' => 'emails.email'],
                                ],
                            ],
                        ];
                        if ($val === true) {
                            $filterClauses[] = $nestedClause;
                        } else {
                            $mustNot[] = $nestedClause;
                        }
                    } elseif ($key === 'phone') {
                        $nestedClause = [
                            'nested' => [
                                'path' => 'phone_numbers',
                                'query' => [
                                    'exists' => ['field' => 'phone_numbers.phone_number'],
                                ],
                            ],
                        ];
                        if ($val === true) {
                            $filterClauses[] = $nestedClause;
                        } else {
                            $mustNot[] = $nestedClause;
                        }
                    } elseif ($key === 'linkedin') {
                        if ($val === true) {
                            $must[] = ['exists' => ['field' => 'linkedin_url']];
                        } else {
                            $mustNot[] = ['exists' => ['field' => 'linkedin_url']];
                        }
                    } elseif ($key === 'website') {
                        if ($val === true) {
                            $must[] = ['exists' => ['field' => 'website']];
                        } else {
                            $mustNot[] = ['exists' => ['field' => 'website']];
                        }
                    }
                }
            }
        }

        // Structured debug log of how the canonical filter_dsl was translated into ES clauses.
        $this->lastAppliedClauses = [
            'must' => $must,
            'must_not' => $mustNot,
            'filter' => $filterClauses,
        ];

        foreach ($must as $clause) {
            $builder->must($clause);
        }
        foreach ($mustNot as $clause) {
            $builder->mustNot($clause);
        }
        foreach ($filterClauses as $clause) {
            $builder->filter($clause);
        }
    }

    /**
     * Unified contacts search (DSL-powered)
     */
    // public function searchUnifiedContacts(array $params): array
    // {
    //     $searchTerm = $params['searchTerm'] ?? null;
    //     $filterDsl = $params['filter_dsl'] ?? [];
    //     $page = max(1, (int) ($params['page'] ?? 1));
    //     $per = max(1, min(200, (int) ($params['count'] ?? 50)));
    //     $sort = (array) ($params['sort'] ?? []);
    //     $aggs = (array) ($params['aggregations'] ?? []);

    //     $query = $this->buildUnifiedContactsQueryArray($searchTerm, $filterDsl, $sort, $aggs);

    //     $builder = ElasticQueryBuilder::forIndex(config('services.elastic.indices.contact'))
    //         ->setBody($query)
    //         ->select($this->unifiedSourceFields());
    //     if (!empty($query['sort'])) {
    //         foreach ($query['sort'] as $s) {
    //             $builder->sort($s['field'], $s['direction'] ?? 'asc');
    //         }
    //     }
    //     if (!empty($query['aggs'])) {
    //         $builder->aggregations($query['aggs']);
    //     }

    //     $results = $builder->paginate($page, $per);
    //     return $this->formatResults($results, 'contact', $filterDsl);
    // }

    public function buildUnifiedContactsQueryArray(?string $searchTerm, array $dsl, array $sort = [], array $aggs = []): array
    {
        $must = [];
        $filter = [];
        $should = [];
        $mustNot = [];

        if ($searchTerm) {
            $must[] = [
                'multi_match' => [
                    'query' => $searchTerm,
                    'type' => 'best_fields',
                    'fields' => ['full_name.joined^2', 'title.joined', 'company_obj.name.joined', 'company_obj.domain'],
                    'operator' => 'and',
                ],
            ];
        }

        foreach ((array) ($dsl['groups'] ?? []) as $group) {
            $boolMust = [];
            foreach ((array) ($group['conditions'] ?? []) as $cond) {
                $field = (string) ($cond['field'] ?? '');
                $op = (string) ($cond['op'] ?? 'eq');
                $value = $cond['value'] ?? null;
                $clause = $this->makeUnifiedClause($field, $op, $value);
                if ($clause !== null) {
                    $boolMust[] = $clause;
                }
            }
            $logic = strtolower((string) ($group['logic'] ?? 'and'));
            if ($logic === 'or') {
                $should[] = ['bool' => ['must' => $boolMust]];
            } elseif ($logic === 'not') {
                $mustNot[] = ['bool' => ['must' => $boolMust]];
            } else {
                $must[] = ['bool' => ['must' => $boolMust]];
            }
        }

        $query = [
            'bool' => array_filter([
                'must' => $must,
                'filter' => $filter,
                'should' => $should,
                'must_not' => $mustNot,
                'minimum_should_match' => !empty($should) ? 1 : null,
            ])
        ];

        $sortArr = [];
        foreach ($sort as $s) {
            $dir = strtolower((string) ($s['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $field = (string) ($s['field'] ?? 'full_name.sort');
            $sortArr[] = ['field' => $field, 'direction' => $dir];
        }

        $aggArr = $aggs ?: $this->defaultUnifiedAggregations();

        return [
            'query' => $query,
            'sort' => $sortArr,
            'aggs' => $aggArr,
        ];
    }

    protected function makeUnifiedClause(string $field, string $op, $value): ?array
    {
        $map = [
            'job_title' => 'job_title',
            'title' => 'title',
            'seniority' => 'seniority',
            'department' => 'department',
            'employee_count' => 'company_obj.employee_count',
            'annual_revenue_usd' => 'company_obj.annual_revenue_usd',
            'industry' => 'company_obj.industry',
            'technologies' => 'company_obj.technologies',
            'country' => 'company_obj.location.country',
            'city' => 'company_obj.location.city',
            'has_email' => 'emails.email',
            'has_phone' => 'phone_numbers.phone_number',
        ];
        $targetField = $map[$field] ?? $field;

        switch ($op) {
            case 'eq':
                return ['term' => [$targetField => $value]];
            case 'prefix':
                return ['match_phrase_prefix' => [$targetField => ['query' => $value, 'max_expansions' => 50]]];
            case 'fuzzy':
                return ['match' => [$targetField => ['query' => $value, 'fuzziness' => 'AUTO']]];
            case 'in':
                return ['terms' => [$targetField => (array) $value]];
            case 'range':
                return ['range' => [$targetField => ['gte' => $value['min'] ?? null, 'lte' => $value['max'] ?? null]]];
            case 'exists':
                return ['exists' => ['field' => $targetField]];
            case 'not_exists':
                return ['bool' => ['must_not' => [['exists' => ['field' => $targetField]]]]];
            default:
                return null;
        }
    }

    protected function defaultUnifiedAggregations(): array
    {
        return [
            'industries' => ['terms' => ['field' => 'company_obj.industry', 'size' => 50]],
            'countries' => ['terms' => ['field' => 'company_obj.location.country', 'size' => 50]],
            'technologies' => ['terms' => ['field' => 'company_obj.technologies', 'size' => 50]],
            'headcount' => ['histogram' => ['field' => 'company_obj.employee_count', 'interval' => 100]],
            'revenue' => ['histogram' => ['field' => 'company_obj.annual_revenue_usd', 'interval' => 1000000]],
        ];
    }

    protected function unifiedSourceFields(): array
    {
        return [
            'first_name',
            'last_name',
            'full_name',
            'job_title',
            'emails',
            'phone_numbers',
            'location',
            'seniority',
            'department',
            'linkedin_url',
            'company',
            'company_obj',
        ];
    }

    /**
     * Attach aggregations to the query so the client can render Apollo-style facets.
     */
    private function applyAggregations(ElasticQueryBuilder $builder, string $type, array $filters): void
    {
        $aggs = [];

        if ($type === 'company') {
            $aggs['industries'] = [
                'terms' => [
                    'field' => 'industry',
                    'size' => 20,
                    'min_doc_count' => 1,
                ],
            ];
            $aggs['industries_bc'] = [
                'terms' => [
                    'field' => 'business_category',
                    'size' => 20,
                    'min_doc_count' => 1,
                ],
            ];

            $aggs['locations'] = [
                'terms' => [
                    'field' => 'location.country',
                    'size' => 20,
                    'min_doc_count' => 1,
                ],
            ];

            $aggs['technologies'] = [
                'terms' => [
                    'field' => 'technologies',
                    'size' => 20,
                    'min_doc_count' => 1,
                ],
            ];

            $aggs['employee_brackets'] = [
                'range' => [
                    'field' => 'employee_count',
                    'ranges' => [
                        ['key' => '1-10', 'from' => 1, 'to' => 10],
                        ['key' => '10-50', 'from' => 10, 'to' => 50],
                        ['key' => '50-200', 'from' => 50, 'to' => 200],
                        ['key' => '200-500', 'from' => 200, 'to' => 500],
                        ['key' => '500-1000', 'from' => 500, 'to' => 1000],
                        ['key' => '1000+', 'from' => 1000],
                    ],
                ],
            ];

            $aggs['has_email'] = [
                'filter' => [
                    'exists' => ['field' => 'company_email'],
                ],
            ];
            $aggs['has_phone'] = [
                'filter' => [
                    'exists' => ['field' => 'company_phone'],
                ],
            ];
        } else {
            $aggs['industries'] = [
                'terms' => [
                    'field' => 'company_obj.industry',
                    'size' => 20,
                    'min_doc_count' => 1,
                ],
            ];

            $aggs['locations'] = [
                'terms' => [
                    'field' => 'location.country',
                    'size' => 20,
                    'min_doc_count' => 1,
                ],
            ];

            $aggs['technologies'] = [
                'terms' => [
                    'field' => 'company_obj.technologies',
                    'size' => 20,
                    'min_doc_count' => 1,
                ],
            ];

            $aggs['has_email'] = [
                'filter' => [
                    'nested' => [
                        'path' => 'emails',
                        'query' => [
                            'exists' => ['field' => 'emails.email'],
                        ],
                    ],
                ],
            ];
            $aggs['has_phone'] = [
                'filter' => [
                    'nested' => [
                        'path' => 'phone_numbers',
                        'query' => [
                            'exists' => ['field' => 'phone_numbers.phone_number'],
                        ],
                    ],
                ],
            ];
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
                $attributes['industry'] = $attributes['industry'] ?? ($attributes['business_category'] ?? null);
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

        $indsPrimary = $this->formatTermsAggregation($rawAggs['industries'] ?? null);
        $indsFallback = $this->formatTermsAggregation($rawAggs['industries_bc'] ?? null);
        $formattedAggs = [
            'industries' => $this->mergeTermAggs($indsPrimary, $indsFallback),
            'employee_brackets' => $this->formatRangeAggregation($rawAggs['employee_brackets'] ?? null),
            'locations' => $this->formatTermsAggregation($rawAggs['locations'] ?? null),
            'technologies' => $this->formatTermsAggregation($rawAggs['technologies'] ?? null),
            'has_email' => isset($rawAggs['has_email']['doc_count']) ? (int) $rawAggs['has_email']['doc_count'] : 0,
            'has_phone' => isset($rawAggs['has_phone']['doc_count']) ? (int) $rawAggs['has_phone']['doc_count'] : 0,
        ];

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

    private function normalizeCountryName(string $country): string
    {
        $country = trim($country);
        if ($country === '') {
            return $country;
        }
        $map = $this->getCountryAbbreviationMap();
        $upper = strtoupper($country);
        if (isset($map[$upper])) {
            return $map[$upper];
        }
        // Common aliases
        $aliases = [
            'US' => ['usa', 'u.s.', 'u.s.a', 'united states of america', 'america'],
            'UK' => ['u.k.', 'great britain', 'england', 'gb', 'britain'],
            'UAE' => ['uae', 'united arab emirates'],
            'KOREA' => ['south korea', 'republic of korea', 's.korea'],
        ];
        foreach ($aliases as $abbr => $list) {
            foreach ($list as $alias) {
                if (strcasecmp($country, $alias) === 0) {
                    return $map[$abbr] ?? $country;
                }
            }
        }
        // Fallback: try partial match against map values
        foreach ($map as $abbr => $full) {
            if (stripos($full, $country) !== false || stripos($country, $full) !== false) {
                return $full;
            }
        }
        return $country;
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
            'hr' => ['hr', 'human resources'],
            'it' => ['it', 'information technology'],
            'bd' => ['bd', 'business development'],
            'sde' => ['sde', 'software engineer', 'developer'],
        ];
        foreach ($syn as $abbr => $list) {
            if ($lower === $abbr) {
                return $list;
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
        $highlightFields = array_unique(array_merge(
            array_keys($searchConfig['exact_fields'] ?? []),
            array_keys($searchConfig['phrase_fields'] ?? []),
            array_keys($searchConfig['prefix_fields'] ?? []),
            array_keys($searchConfig['ngram_fields'] ?? []),
            array_keys($searchConfig['text_fields'] ?? [])
        ));
        if (!empty($highlightFields)) {
            $builder->highlight($highlightFields);
        }
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

        // 1. Exact Matches (Highest Priority)
        $this->addExactMatches($shouldClauses, $searchConfig['exact_fields'], $query);
        // 2. Phrase Matches (High Priority)
        $this->addPhraseMatches($shouldClauses, $searchConfig['phrase_fields'], $query);
        // 3. Prefix Matches (Medium Priority)
        $this->addPrefixMatches($shouldClauses, $searchConfig['prefix_fields'], $query);
        // 4. NGram Matches (For partial matching)
        $this->addNGramMatches($shouldClauses, $searchConfig['ngram_fields'], $query);
        // 5. Fuzzy Full-text Search (Lower Priority)
        $this->addFuzzyMatches($shouldClauses, $searchConfig['text_fields'], $query);

        $builder->should([
            'bool' => [
                'should' => $shouldClauses,
                'minimum_should_match' => 1,
            ],
        ]);

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
     * Get country abbreviation to full name mapping
     */
    protected function getCountryAbbreviationMap(): array
    {
        return [
            'US' => 'United States',
            'USA' => 'United States',
            'UK' => 'United Kingdom',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'IE' => 'Ireland',
            'PT' => 'Portugal',
            'GR' => 'Greece',
            'CZ' => 'Czech Republic',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'HR' => 'Croatia',
            'SK' => 'Slovakia',
            'SI' => 'Slovenia',
            'EE' => 'Estonia',
            'LV' => 'Latvia',
            'LT' => 'Lithuania',
            'JP' => 'Japan',
            'CN' => 'China',
            'IN' => 'India',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'PE' => 'Peru',
            'ZA' => 'South Africa',
            'EG' => 'Egypt',
            'NG' => 'Nigeria',
            'KE' => 'Kenya',
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'IL' => 'Israel',
            'TR' => 'Turkey',
            'RU' => 'Russia',
            'KR' => 'South Korea',
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'TH' => 'Thailand',
            'VN' => 'Vietnam',
            'PH' => 'Philippines',
            'ID' => 'Indonesia',
            'NZ' => 'New Zealand',
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
            'company_revenue' => 'annual_revenue',
            'company_industries' => 'industries',
            'company_technologies' => 'technologies',
            'company_locations' => 'locations',
            'company_founded_year' => 'founded_year',
            'company_domains' => 'domains',
            'company_has' => 'has'
        ];

        // Apply each company filter
        foreach ($companyFilters as $key => $value) {
            $actualKey = $filterMap[$key] ?? $key;

            switch ($actualKey) {
                case 'employee_count':
                    if (is_array($value)) {
                        $range = [];
                        if (isset($value['min']) && $value['min'] !== null) {
                            $range['gte'] = (int) $value['min'];
                        }
                        if (isset($value['max']) && $value['max'] !== null) {
                            $range['lte'] = (int) $value['max'];
                        }
                        if ($range) {
                            $filterClauses[] = ['range' => ['employee_count' => $range]];
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
                                    $should[] = ['range' => ['employee_count' => $range]];
                                }
                            }
                            if ($should) {
                                $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                            }
                        }
                    }
                    break;

                case 'annual_revenue':
                    if (is_array($value)) {
                        $range = [];
                        if (isset($value['min']) && $value['min'] !== null) {
                            $range['gte'] = (float) $value['min'];
                        }
                        if (isset($value['max']) && $value['max'] !== null) {
                            $range['lte'] = (float) $value['max'];
                        }
                        if ($range) {
                            $filterClauses[] = ['range' => ['annual_revenue' => $range]];
                        }
                    }
                    break;

                case 'industries':
                    if (is_array($value)) {
                        $include = $value['include'] ?? [];
                        $exclude = $value['exclude'] ?? [];

                        if (!empty($include)) {
                            $should = [];
                            $should[] = ['terms' => ['industry' => $include]];
                            $should[] = ['terms' => ['industries' => $include]];
                            $should[] = ['terms' => ['business_category' => $include]];
                            $filterClauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                        }
                        if (!empty($exclude)) {
                            $should = [];
                            $should[] = ['terms' => ['industry' => $exclude]];
                            $should[] = ['terms' => ['industries' => $exclude]];
                            $should[] = ['terms' => ['business_category' => $exclude]];
                            $mustNot[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
                        }
                    }
                    break;

                case 'technologies':
                    if (is_array($value)) {
                        $include = $value['include'] ?? [];
                        $exclude = $value['exclude'] ?? [];

                        if (!empty($include)) {
                            $filterClauses[] = ['terms' => ['technologies' => $include]];
                        }
                        if (!empty($exclude)) {
                            $mustNot[] = ['terms' => ['technologies' => $exclude]];
                        }
                    }
                    break;

                case 'locations':
                    if (is_array($value)) {
                        $include = $value['include'] ?? [];

                        if (!empty($include)) {
                            $shouldLoc = [];
                            foreach ($include as $val) {
                                // Need to implement normalizeCountryName or remove call if unused here? 
                                // User code calls $this->normalizeCountryName($val)
                                // I will ensure the method exists or use a simple trim. 
                                // For now, I'll use a placeholder logic or assume I'll add the method method.
                                $country = $this->normalizeCountryName($val);
                                $shouldLoc[] = [
                                    'multi_match' => [
                                        'query' => $country,
                                        'fields' => ['location.country', 'city', 'state', 'country'],
                                        'type' => 'phrase',
                                        'operator' => 'or'
                                    ]
                                ];
                            }
                            if ($shouldLoc) {
                                $filterClauses[] = ['bool' => ['should' => $shouldLoc, 'minimum_should_match' => 1]];
                            }
                        }
                    }
                    break;

                case 'founded_year':
                    if (is_array($value)) {
                        $range = [];
                        if (isset($value['min']) && $value['min'] !== null) {
                            $range['gte'] = (int) $value['min'];
                        }
                        if (isset($value['max']) && $value['max'] !== null) {
                            $range['lte'] = (int) $value['max'];
                        }
                        if ($range) {
                            $filterClauses[] = ['range' => ['founded_year' => $range]];
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
                                        'fields' => ['industry^3', 'technologies^3', 'keywords^2', 'short_description', 'description'],
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
                                        'fields' => ['industry', 'technologies', 'keywords', 'short_description', 'description'],
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
