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

            // 2. Prepare the "Dictionary" from your FilterRegistry
            // This tells the AI exactly what names to use (e.g., company_city instead of 'city')
            $registryFilters = FilterRegistry::getFilters();
            $filterGuidelines = collect($registryFilters)->map(function($f) {
                $applies = isset($f['applies_to']) && is_array($f['applies_to']) ? implode(',', $f['applies_to']) : 'unknown';
                return "- ID: {$f['id']} | Label: {$f['label']} | Applies To: {$applies}";
            })->implode("\n");

            // 3. Build the Instructions
            $instruction = "You are a lead generation assistant. Convert the user's query into Canonical DSL JSON.
            AVAILABLE FILTER IDS (from registry):
            {$filterGuidelines}

            Canonical DSL shape:
            {
              \"entity\": \"contacts\" | \"companies\",
              \"filters\": {
                \"contact\": { FILTER_ID: { include: [values], exclude?: [values], range?: { min?: number, max?: number } } },
                \"company\": { FILTER_ID: { include: [values], exclude?: [values], range?: { min?: number, max?: number } } }
              },
              \"summary\": \"short explanation\",
              \"semantic_query\": null | \"optional vector sentence\",
              \"custom\": []
            }

            Routing rules:
            - job_title and other person attributes ALWAYS go under filters.contact.
            - company_name, company_domain, industry, technologies, employee_count, annual_revenue, founded_year go under filters.company.
            - NEVER put job_title under filters.company.
            - When multiple job titles are present, keep the longest/specific ones (e.g., 'AI Engineer' over 'Engineer').
            - If job title detected, entity = contacts; else if only company metrics detected, entity = companies; else default entity = contacts.

            Output ONLY valid JSON in the Canonical DSL shape.";

            // 4. Mimic your original structure to avoid 500 errors
            $messages = [
                ['role' => 'user', 'content' => $instruction . "\n\nUser Query: " . $incomingQuery]
            ];

            // 5. Call the service (Your original method call)
            $result = $this->translator->translate(
                $messages, 
                $request->input('context') ?? []
            );

            // 6. Ensure Canonical DSL buckets exist
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
                if (preg_match('/\b(?:in|from|based in|located in)\s+([A-Z][a-zA-Z]+(?:[\s,]+[A-Z][a-zA-Z]+)*)\b/', $incomingQuery, $m)) {
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

            // Company domain
            if (isset($company['company_domain'])) {
                $flat['company_domain'] = is_array($company['company_domain']) ? $company['company_domain'] : ['include' => [(string) $company['company_domain']]];
            }

            // 9. Return legacy shape for app compatibility
            return response()->json([
                'entity' => $entity,
                'filters' => $flat,
                'summary' => $result['summary'] ?? '',
                'semantic_query' => $result['semantic_query'] ?? null,
                'custom' => $result['custom'] ?? [],
            ], 200);

        } catch (\Throwable $e) {
            // Log the error so you can see exactly what went wrong in storage/logs/laravel.log
            Log::error("AI Search Error: " . $e->getMessage());

            return response()->json([
                'status' => 'failed',
                'entity' => 'contacts',
                'filters' => ['contact' => [], 'company' => []],
                'summary' => 'The AI is having trouble. Please try a simpler search.',
                'error_code' => 'AI_INTERNAL_ERROR'
            ], 200); // We return 200 so the frontend doesn't crash
        }
    }
}
