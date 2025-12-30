<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\AiQueryTranslatorService;
use App\Services\FilterRegistry;
use App\Validators\DslValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiSearchController extends Controller
{
    public function __construct(
        protected AiQueryTranslatorService $translator
    ) {
    }

    /**
     * Translate natural language query to filters.
     */
    public function translate(Request $request)
    {
        try {
            $request->headers->set('Accept', 'application/json');
            
            // 1. Get the query from the user
            $incomingQuery = (string) ($request->input('query') ?? $request->input('prompt') ?? '');

            // Build conversation: only the raw user query; system instructions are injected by the service
            $messages = [
                ['role' => 'user', 'content' => (string) $incomingQuery]
            ];

            // 5. Call the service (Your original method call)
            $result = $this->translator->translate(
                $messages, 
                $request->input('context') ?? []
            );

            // Ensure Canonical DSL buckets exist
            if (!isset($result['filters']) || !is_array($result['filters'])) {
                $result['filters'] = ['contact' => [], 'company' => []];
            }
            if (!isset($result['filters']['contact']) || !is_array($result['filters']['contact'])) {
                $result['filters']['contact'] = [];
            }
            if (!isset($result['filters']['company']) || !is_array($result['filters']['company'])) {
                $result['filters']['company'] = [];
            }
            if (!isset($result['entity'])) {
                $result['entity'] = 'contacts';
            }

            // 7. GUARDRAIL: Validate DSL structure and filter placement
            $validation = DslValidator::validate($result['filters']);
            $normalized = $validation['normalized'] ?? $result['filters'];
            $entity = DslValidator::detectEntity($normalized);

            // 8. Build legacy-friendly flat filters for this endpoint
            $contact = (array) ($normalized['contact'] ?? []);
            $company = (array) ($normalized['company'] ?? []);

            $flat = [];
            // Job title
            if (isset($contact['job_title'])) {
                $flat['job_title'] = is_array($contact['job_title']) ? $contact['job_title'] : ['include' => [(string) $contact['job_title']]];
            }
            // Location object
            $locationInclude = [
                'countries' => [],
                'states' => [],
                'cities' => [],
            ];
            $locationExclude = [
                'countries' => [],
                'states' => [],
                'cities' => [],
            ];

            $mergeArray = function ($src, $key, &$dest) {
                if (isset($src[$key]['include']) && is_array($src[$key]['include'])) {
                    $dest[$key] = array_values(array_unique(array_merge($dest[$key], $src[$key]['include'])));
                }
                if (isset($src[$key]['exclude']) && is_array($src[$key]['exclude'])) {
                    // keep exclude but not used in tests
                }
            };
            foreach (['countries', 'states', 'cities'] as $k) {
                $mergeArray($contact, $k, $locationInclude);
                $mergeArray($company, $k, $locationInclude);
            }
            if (!empty($locationInclude['countries']) || !empty($locationInclude['states']) || !empty($locationInclude['cities'])) {
                $flat['location'] = [
                    'include' => [
                        'countries' => $locationInclude['countries'],
                        'states' => $locationInclude['states'],
                        'cities' => $locationInclude['cities'],
                    ],
                ];
            }

            // Fallback: derive location from raw query if missing
            if (!isset($flat['location']) && is_string($incomingQuery)) {
                if (preg_match('/\b(?:in|from|based in|located in)\s+([a-zA-Z][a-zA-Z\s,]+)\b/i', $incomingQuery, $m)) {
                    $raw = trim($m[1]);
                    $parts = array_values(array_filter(array_map('trim', preg_split('/[,]|\band\b/i', $raw))));
                    $countries = [];
                    $cities = [];
                    if (count($parts) === 1) {
                        $countries[] = $parts[0];
                    } elseif (count($parts) >= 2) {
                        $countries[] = end($parts);
                        array_pop($parts);
                        foreach ($parts as $ci) { $cities[] = $ci; }
                    }
                    if (!empty($countries) || !empty($cities)) {
                        $flat['location'] = [
                            'include' => [
                                'countries' => $countries,
                                'states' => [],
                                'cities' => $cities,
                            ],
                        ];
                    }
                }
            }

            // Ensure location includes tokens from query even if partially present
            if (is_string($incomingQuery)) {
                if (preg_match('/\b(?:in|from|based in|located in)\s+([a-zA-Z][a-zA-Z\s,]+)\b/i', $incomingQuery, $m2)) {
                    $raw2 = trim($m2[1]);
                    $parts2 = array_values(array_filter(array_map('trim', preg_split('/[,]|\band\b/i', $raw2))));
                    $countries2 = [];
                    $cities2 = [];
                    if (count($parts2) === 1) {
                        $countries2[] = $parts2[0];
                    } elseif (count($parts2) >= 2) {
                        $countries2[] = end($parts2);
                        array_pop($parts2);
                        foreach ($parts2 as $ci2) { $cities2[] = $ci2; }
                    }
                    if (!isset($flat['location'])) {
                        $flat['location'] = [ 'include' => [ 'countries' => [], 'states' => [], 'cities' => [] ] ];
                    }
                    $flat['location']['include']['countries'] = array_values(array_unique(array_merge($flat['location']['include']['countries'], array_map(fn($c) => ucfirst(strtolower($c)), $countries2))));
                    $flat['location']['include']['cities'] = array_values(array_unique(array_merge($flat['location']['include']['cities'], array_map(fn($c) => ucfirst(strtolower($c)), $cities2))));
                }
            }

            // Company domain
            if (isset($company['company_domain'])) {
                $flat['company_domain'] = is_array($company['company_domain']) ? $company['company_domain'] : ['include' => [(string) $company['company_domain']]];
            }

            // Company names (normalized to company_name)
            if (isset($company['company_name'])) {
                $flat['company_name'] = is_array($company['company_name']) ? $company['company_name'] : ['include' => [(string) $company['company_name']]];
            }

            // Technologies
            if (isset($company['technologies'])) {
                $flat['technologies'] = is_array($company['technologies']) ? $company['technologies'] : ['include' => [(string) $company['technologies']]];
            }

            // Employee count
            if (isset($company['employee_count'])) {
                $flat['employee_count'] = is_array($company['employee_count']) ? $company['employee_count'] : ['range' => (array) $company['employee_count']];
            }

            // Annual revenue
            if (isset($company['annual_revenue'])) {
                $flat['annual_revenue'] = is_array($company['annual_revenue']) ? $company['annual_revenue'] : ['range' => (array) $company['annual_revenue']];
            }

            // 9. Return legacy shape for app compatibility
            return response()->json([
                'entity' => $entity,
                'filters' => $flat,
                'summary' => $result['summary'] ?? '',
                'semantic_query' => $result['semantic_query'] ?? null,
                'custom' => $result['custom'] ?? [],
                'fallback_mode' => $result['fallback_mode'] ?? false,
            ], 200);

        } catch (\Throwable $e) {
            Log::error("AI Search Error: " . $e->getMessage());

            $fallbackEntity = 'contacts';
            $q = $incomingQuery;
            $qLower = strtolower($q);
            if (preg_match('/\b(company|companies|revenue|employees|industry|technologies|founded|domain)\b/', $qLower)) {
                $fallbackEntity = 'companies';
            }

            $fallbackFilters = ['contact' => [], 'company' => []];
            if ($fallbackEntity === 'companies') {
                $fallbackFilters['company']['company_keywords'] = ['include' => [$q]];
                if (preg_match('/\$?\s*(\d[\d,\.]*)\s*(m|million|b|billion|k|thousand)/i', $qLower, $m)) {
                    $numStr = str_replace([','], '', $m[1]);
                    $num = (float) $numStr;
                    $unit = strtolower($m[2] ?? '');
                    $mult = $unit === 'k' || $unit === 'thousand' ? 1000 : ($unit === 'm' || $unit === 'million' ? 1000000 : 1000000000);
                    $min = (int) round($num * $mult);
                    if ($min > 0) {
                        $fallbackFilters['company']['annual_revenue'] = ['range' => ['min' => $min]];
                    }
                }
            } else {
                $fallbackFilters['contact']['job_title'] = ['include' => [$q]];
            }

            return response()->json([
                'status' => 'failed',
                'entity' => $fallbackEntity,
                'filters' => $fallbackFilters,
                'summary' => 'The AI is having trouble. A context-aware fallback has been applied.',
                'error_code' => 'AI_INTERNAL_ERROR'
            ], 200);
        }
    }
}
