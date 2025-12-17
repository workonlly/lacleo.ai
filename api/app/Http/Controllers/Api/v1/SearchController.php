<?php

namespace App\Http\Controllers\Api\v1;

use App\Exceptions\QueryValidationException;
use App\Filters\FilterManager;
use App\Http\Controllers\Controller;
use App\Services\SearchService;
use App\Utilities\SearchUrlParser;
use App\Validators\SearchQueryValidator;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class SearchController extends Controller
{
    public function __construct(
        protected SearchService $searchService,
        protected SearchQueryValidator $searchQueryValidator,
        protected FilterManager $filterManager
    ) {
    }

    /**
     * Search with filters
     */
    public function search(Request $request): JsonResponse
    {
        Log::debug('Search request received', [
            'query_string' => $request->getQueryString(),
            'route_parameters' => $request->route()->parameters(),
        ]);

        try {
            $searchParams = $this->prepareSearchParameters($request);

            $cacheKey = 'search:' . sha1(json_encode($searchParams));
            $isPublic = $request->user() === null;

            $results = $isPublic
                ? Cache::remember($cacheKey, now()->addSeconds(60), function () use ($searchParams) {
                    return $this->executeSearch($searchParams);
                })
                : $this->executeSearch($searchParams);
            Log::debug('Search executed successfully', [
                'total_results' => $results['total'] ?? 0,
                'page' => $searchParams['queryParams']['page'] ?? 1,
            ]);
            $debugFlag = $request->query('debug');
            if ($debugFlag === '1' || $debugFlag === 1 || $debugFlag === true) {
                $built = $this->searchService->buildQueryArray(
                    $searchParams['type'],
                    $searchParams['variables']['searchTerm'] ?? null,
                    $searchParams['variables']['filter_dsl'] ?? [],
                    $searchParams['sort'] ?? []
                );
                $indexMap = [
                    'company' => 'stage_lacleo_company_stats',
                    'contact' => 'stage_lacleo_contact_stats',
                ];
                $results['debug'] = [
                    'params' => $searchParams,
                    'index_used' => $indexMap[$searchParams['type']] ?? null,
                    'raw_query' => $built,
                ];
            }

            return response()->json($results);
        } catch (Exception $e) {
            Log::error('Search operation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->handleSearchException($e);
        }
    }



    /**
     * List all available filters
     */
    public function getFilters(): JsonResponse
    {
        Log::debug('Retrieving all filters');

        $filters = $this->filterManager->getActiveFilters()
            ->groupBy('filter_group_id')
            ->map(function ($groupFilters) {
                return $this->formatFilterGroup($groupFilters);
            })
            ->values()
            ->toArray();

        Log::debug('Filters retrieved successfully', [
            'filter_count' => count($filters),
        ]);

        return response()->json(['data' => $filters]);
    }

    /**
     * Get values for a specific filter
     */
    public function getFilterValues(Request $request): JsonResponse
    {
        Log::debug('Filter values request received', [
            'query_parameters' => $request->query(),
        ]);

        $validator = $this->validateFilterValuesRequest($request);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'details' => $validator->errors()], 422);
        }

        $requestQuery = $validator->validated();

        try {
            $values = $this->filterManager->getFilterValues(
                $requestQuery['filter'],
                $requestQuery['q'] ?? null,
                $requestQuery['page'] ?? 1,
                $requestQuery['count'] ?? 10
            );

            Log::debug('Filter values retrieved successfully', [
                'filter' => $requestQuery['filter'],
                'count' => count($values['data'] ?? []),
            ]);

            return response()->json($values);
        } catch (Exception $e) {
            Log::error('Filter values retrieval failed', [
                'filter' => $requestQuery['filter'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->handleFilterValuesException($e);
        }
    }

    /**
     * Debug built ES query for given filters (admin-only)
     */
    public function debugQuery(Request $request): JsonResponse
    {
        $params = $this->prepareSearchParameters($request);
        $query = $this->searchService->buildQueryArray(
            $params['type'],
            $params['variables']['searchTerm'] ?? null,
            $params['variables']['filter_dsl'] ?? [],
            $params['sort'] ?? []
        );

        return response()->json(['query' => $query]);
    }

    /**
     * Prepare search parameters from request
     */
    private function prepareSearchParameters(Request $request): array
    {
        Log::debug('=== SEARCH REQUEST DEBUG START ===', [
            'query_string' => $request->getQueryString(),
            'full_request' => $request->all(),
            'route_type' => $request->route('type'),
        ]);

        $searchParams = SearchUrlParser::parseQuery($request->getQueryString());
        $searchParams['type'] = $request->route('type', 'company');

        if (isset($searchParams['variables']['variables']) && is_array($searchParams['variables']['variables'])) {
            $searchParams['variables'] = $searchParams['variables']['variables'];
        }

        // Fallbacks: accept simple query parameters when Voyager payload is not used.
        // Map `q` → variables.searchTerm
        if (empty($searchParams['variables']['searchTerm'] ?? null) && isset($searchParams['queryParams']['q'])) {
            $searchParams['variables']['searchTerm'] = (string) $searchParams['queryParams']['q'];
            unset($searchParams['queryParams']['q']);
        }

        // Map `filter_dsl` in queryParams → variables.filter_dsl
        if (empty($searchParams['variables']['filter_dsl'] ?? null) && isset($searchParams['queryParams']['filter_dsl'])) {
            $raw = $searchParams['queryParams']['filter_dsl'];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $searchParams['variables']['filter_dsl'] = $decoded;
                } else {
                    Log::warning('Failed to decode filter_dsl JSON', [
                        'raw' => $raw,
                        'json_error' => json_last_error_msg(),
                    ]);
                }
            } elseif (is_array($raw)) {
                $searchParams['variables']['filter_dsl'] = $raw;
            }
            unset($searchParams['queryParams']['filter_dsl']);
        }

        // Map `semantic_query` to variables
        if (isset($searchParams['queryParams']['semantic_query'])) {
            $searchParams['variables']['semantic_query'] = $searchParams['queryParams']['semantic_query'];
        }

        // Ensure filter_dsl is always an array
        if (!isset($searchParams['variables']['filter_dsl']) || !is_array($searchParams['variables']['filter_dsl'])) {
            $searchParams['variables']['filter_dsl'] = [];
        }

        // Normalize empty search term to null (not empty string)
        if (isset($searchParams['variables']['searchTerm']) && $searchParams['variables']['searchTerm'] === '') {
            $searchParams['variables']['searchTerm'] = null;
        }

        Log::debug('Search parameters parsed', [
            'type' => $searchParams['type'],
            'has_search_term' => !empty($searchParams['variables']['searchTerm'] ?? null),
            'search_term_length' => isset($searchParams['variables']['searchTerm']) ? strlen($searchParams['variables']['searchTerm']) : 0,
            'has_filter_dsl' => !empty($searchParams['variables']['filter_dsl'] ?? []),
            'filter_dsl_keys' => array_keys($searchParams['variables']['filter_dsl'] ?? []),
            'page' => $searchParams['queryParams']['page'] ?? null,
            'count' => $searchParams['queryParams']['count'] ?? null,
        ]);

        try {
            $this->searchQueryValidator->validate($searchParams);
            Log::debug('Validation passed');
        } catch (QueryValidationException $e) {
            Log::error('Validation failed', [
                'errors' => $e->getErrors(),
                'parsed_params' => $searchParams,
            ]);
            throw $e;
        }

        Log::debug('=== SEARCH REQUEST DEBUG END ===');

        return $searchParams;
    }

    /**
     * Execute search with parameters
     */
    private function executeSearch(array $params): array
    {
        Log::debug('Executing search', [
            'type' => $params['type'],
            'search_term' => $params['variables']['searchTerm'] ?? null,
            'filter_keys' => array_keys($params['variables']['filter_dsl'] ?? []),
            'page' => $params['queryParams']['page'] ?? 1,
            'count' => $params['queryParams']['count'] ?? 10,
        ]);

        return $this->searchService->search(
            $params['type'],
            $params['variables']['searchTerm'] ?? null,
            $params['variables']['filter_dsl'] ?? [],
            $params['sort'] ?? [],
            max(1, min((int) ($params['queryParams']['page'] ?? 1), 100)),
            max(1, min((int) ($params['queryParams']['count'] ?? 10), 100)),
            $params['variables']['semantic_query'] ?? null,
        );
    }

    /**
     * Format filter group data
     *
     * @param  \Illuminate\Support\Collection  $groupFilters
     */
    private function formatFilterGroup($groupFilters): array
    {
        $firstFilter = $groupFilters->first();

        return [
            'group_id' => $firstFilter->filterGroup->id,
            'group_name' => $firstFilter->filterGroup->name,
            'group_description' => $firstFilter->filterGroup->name,
            'filters' => $groupFilters->map(function ($filter) {
                return [
                    'id' => $filter->filter_id,
                    'name' => $filter->name,
                    'type' => $filter->value_type,
                    'input_type' => $filter->input_type,
                    'is_searchable' => $filter->is_searchable,
                    'allows_exclusion' => $filter->allows_exclusion,
                    'supports_value_lookup' => $filter->supports_value_lookup,
                    'filter_type' => $filter->filter_type,
                ];
            })->values(),
        ];
    }

    /**
     * Validate filter values request
     */
    private function validateFilterValuesRequest(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->query(), [
            'filter' => 'required|string|exists:filters,filter_id',
            'q' => 'sometimes|nullable|string|max:100',
            'page' => 'sometimes|nullable|integer|min:1',
            'count' => 'sometimes|nullable|integer|between:1,100',
            'level' => 'nullable|string',
            'parents' => 'array',
        ]);
    }

    /**
     * Handle search-related exceptions
     */
    private function handleSearchException(Exception $e): JsonResponse
    {
        if ($e instanceof \Elastic\Elasticsearch\Exception\ServerResponseException) {
            return response()->json([
                'error' => 'ELASTIC_UNAVAILABLE',
                'message' => 'Search backend is unavailable',
            ], 503);
        }
        if (stripos($e->getMessage(), 'No alive nodes') !== false) {
            return response()->json([
                'error' => 'ELASTIC_UNAVAILABLE',
                'message' => 'Search backend is unavailable',
            ], 503);
        }
        if ($e instanceof QueryValidationException) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
                'details' => $e->getErrors(),
            ], 422);
        }

        if ($e instanceof InvalidArgumentException) {
            return response()->json([
                'error' => config('app.debug') ? $e->getMessage() : null,
                'message' => 'Invalid Request',
            ], 400);
        }

        return response()->json([
            'message' => 'An unexpected error occurred',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }

    /**
     * Handle filter values exceptions
     */
    private function handleFilterValuesException(Exception $e): JsonResponse
    {
        if ($e instanceof ModelNotFoundException) {
            return response()->json(['message' => 'Filter not found'], 404);
        }

        if ($e instanceof InvalidArgumentException) {
            return response()->json([
                'error' => config('app.debug') ? $e->getMessage() : null,
                'message' => 'Invalid Request',
            ], 400);
        }

        return response()->json([
            'message' => 'Internal Server Error',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
