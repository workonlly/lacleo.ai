<?php

namespace App\Services;

use App\Validators\DslValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiQueryTranslatorService
{
    /**
     * Job title keywords for deterministic detection
     */
    private const JOB_TITLE_KEYWORDS = [
        'engineer', 'developer', 'programmer', 'architect', 'analyst',
        'manager', 'director', 'chief', 'officer', 'vp', 'vice president',
        'cto', 'ceo', 'cfo', 'coo', 'head of', 'lead',
        'specialist', 'consultant', 'coordinator', 'associate',
        'designer', 'scientist', 'researcher', 'technician',
        'administrator', 'representative', 'agent', 'advisor',
        'people', 'person', 'professional', 'staff', 'employee', 'team member',
    ];

    /**
     * Company metric keywords for deterministic detection
     */
    private const COMPANY_KEYWORDS = [
        'company', 'companies', 'organization', 'business', 'firm',
        'startup', 'enterprise', 'corporation', 'industry', 'sector',
        'revenue', 'employees', 'headcount', 'founded', 'established',
    ];

    /**
     * Translate a natural language conversation into structured filters using TinyLlama.
     *
     * @param array $messages History of messages [['role' => 'user'|'assistant', 'content' => '...']]
     * @param array $context Metadata like ['lastResultCount' => 0]
     * @return array{entity: string, filters: array, summary: string, semantic_query: string|null, custom: array}
     */
    public function translate(array $messages, array $context = []): array
    {
        // Extract query from messages (last user message)
        $query = '';
        foreach (array_reverse($messages) as $msg) {
            if (isset($msg['role']) && $msg['role'] === 'user' && isset($msg['content'])) {
                $query = $msg['content'];
                break;
            }
        }

        // STEP 1: Deterministic pre-analysis
        $preAnalysis = $this->analyzeQueryDeterministically($query);
        
        // STEP 2: Try AI translation with timeout
        $baseUrl = config('services.ollama.base_url');
        $model = config('services.ollama.chat_model');
        
        if (!$baseUrl || !$model) {
            Log::warning('AI service not configured, using rule-based fallback');
            return $this->buildRuleBasedResponse($query, $preAnalysis);
        }

        try {
            $aiResult = $this->callAiService($messages, $context, $baseUrl, $model);
            // STEP 3: Validate and correct AI output
            return $this->validateAndCorrectAiOutput($aiResult, $query, $preAnalysis);
        } catch (\Exception $e) {
            Log::warning('AI primary endpoint failed, attempting fallback host: ' . $e->getMessage());
            $altUrl = $this->resolveAlternateBaseUrl($baseUrl);
            if ($altUrl !== $baseUrl) {
                try {
                    $aiResult = $this->callAiService($messages, $context, $altUrl, $model);
                    return $this->validateAndCorrectAiOutput($aiResult, $query, $preAnalysis);
                } catch (\Exception $e2) {
                    Log::error('AI alternate endpoint failed: ' . $e2->getMessage());
                }
            }
            return $this->buildRuleBasedResponse($query, $preAnalysis);
        }
    }

    /**
     * Analyze query deterministically using rules
     */
    private function analyzeQueryDeterministically(string $query): array
    {
        $queryLower = strtolower($query);
        
        // Detect job titles (more specific patterns first)
        $jobTitles = [];
        $hasJobTitleKeyword = false;
        
        foreach (self::JOB_TITLE_KEYWORDS as $keyword) {
            if (stripos($queryLower, $keyword) !== false) {
                $hasJobTitleKeyword = true;
                $jobTitles[] = $keyword;
            }
        }
        
        // Detect company names (look for "at X" patterns)
        $companyNames = [];
        if (preg_match('/\b(?:at|for|with|from)\s+([\w\s&.-]+?)(?:\s+(?:who|that|with|in|and|or|based|located)|[\.,;:]|$)/i', $query, $matches)) {
            $companyNames[] = trim($matches[1]);
        }
        // Detect company domains
        $companyDomains = [];
        if (preg_match_all('/\b([a-zA-Z0-9-]+\.(?:com|io|net|org|co|ai|dev))\b/i', $query, $dmatches)) {
            foreach ($dmatches[1] as $d) {
                $companyDomains[] = strtolower(trim($d));
            }
        }
        
        // Detect company metrics keywords
        $hasCompanyMetrics = false;
        foreach (self::COMPANY_KEYWORDS as $keyword) {
            if (stripos($queryLower, $keyword) !== false) {
                $hasCompanyMetrics = true;
                break;
            }
        }
        
        // Detect location (cities, states, countries)
        $locations = [
            'countries' => [],
            'states' => [],
            'cities' => [],
        ];
        if (preg_match('/\b(?:in|from|based in|located in)\s+([a-zA-Z][a-zA-Z\s,]+)\b/i', $query, $matches)) {
            $raw = trim($matches[1]);
            $parts = array_values(array_filter(array_map('trim', preg_split('/[,]|\band\b/i', $raw))));
            if (count($parts) === 1) {
                $locations['countries'][] = $parts[0];
            } elseif (count($parts) >= 2) {
                // Heuristic: last token is country, earlier tokens are cities
                $locations['countries'][] = end($parts);
                array_pop($parts);
                foreach ($parts as $ci) {
                    $locations['cities'][] = $ci;
                }
            }
        }
        
        // Deterministic entity detection
        // Rule: If job title keywords exist → contacts
        //       Else if company metrics exist → companies  
        //       Else → contacts (default)
        $entity = $hasJobTitleKeyword ? 'contacts' : ($hasCompanyMetrics ? 'companies' : 'contacts');
        
        return [
            'entity' => $entity,
            'job_titles' => $jobTitles,
            'company_names' => $companyNames,
            'company_domains' => $companyDomains,
            'has_job_title_keyword' => $hasJobTitleKeyword,
            'has_company_metrics' => $hasCompanyMetrics,
            'locations' => $locations,
        ];
    }

    /**
     * Call AI service (Ollama)
     */
    private function callAiService(array $messages, array $context, string $baseUrl, string $model): array
    {
        // Preamble for context-awareness (Result Count)
        $contextPreamble = "";
        if (isset($context['lastResultCount'])) {
            $count = (int) $context['lastResultCount'];
            $contextPreamble = "SYSTEM NOTE: The user's PREVIOUS search yielded exactly {$count} results.\n";
            if ($count === 0) {
                $contextPreamble .= "Since the previous result was 0, you SHOULD suggest broadening the filters (removing strict constraints) or explaining why it might be empty.\n";
            }
        }

        // Build dynamic filter menu from registry
        $registryFilters = \App\Services\FilterRegistry::getFilters();
        $availableFilterList = collect($registryFilters)
            ->map(fn($f) => "ID: {$f['id']} | Label: {$f['label']} | Group: {$f['group']}")
            ->implode("\n");

        $systemPrompt = <<<EOT
You are a lead generation assistant. Convert the user's request into a JSON filter object.
You must ALWAYS output strict JSON. Never output text outside JSON.
Do not infer facts. Only translate the user's instructions into filters.

{$contextPreamble}

ONLY use the following Filter IDs:
{$availableFilterList}

CRITICAL RULES (NON-NEGOTIABLE):
1. Job titles (engineer, manager, CEO, etc.) MUST go under "contact" key ONLY
2. Company attributes (company names, industries, revenue, etc.) MUST go under "company" key ONLY
3. NEVER put job_title under the "company" key
4. When multiple job titles exist, keep the longest/most specific one
5. "AI Engineers at Microsoft" means:
   - contact.job_title = ["AI Engineer"]
   - company.company_names = ["Microsoft"]

Entity Detection:
- If job title terms are present → set "entity" to "contacts"
- Else if company metrics are present → set "entity" to "companies"
- Else default to "contacts"

Output Format (Canonical DSL):
{
  "entity": "contacts" | "companies",
  "filters": {
    "contact": { "job_title": { "include": ["CEO"] } },
    "company": { "company_names": { "include": ["London"] } }
  },
  "summary": "Searching for CEOs at companies in London",
  "semantic_query": null,
  "custom": []
}

Return ONLY valid JSON in this exact format.
EOT;

        // Build the messages array for Ollama
        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

        // Append conversation history (sanitized)
        foreach ($messages as $msg) {
            if (isset($msg['role'], $msg['content']) && in_array($msg['role'], ['user', 'assistant'])) {
                $apiMessages[] = [
                    'role' => $msg['role'],
                    'content' => substr($msg['content'], 0, 1000)
                ];
            }
        }

        // Ollama API Call (fail fast and respect app timeout)
        $timeout = (int) env('AI_TRANSLATE_TIMEOUT', 30);
        if ($timeout <= 0) {
            $timeout = 30;
        }
        
        $response = Http::timeout($timeout)
            ->connectTimeout(min(10, $timeout))
            ->post(rtrim((string) $baseUrl, '/') . '/api/chat', [
                'model' => $model,
                'messages' => $apiMessages,
                'stream' => false,
                'format' => 'json',
                'options' => [
                    'temperature' => 0,
                    // keep model loaded to avoid unload delays/timeouts
                    'keep_alive' => env('OLLAMA_KEEP_ALIVE', '10m'),
                ]
            ]);

        if ($response->failed()) {
            throw new \Exception('Ollama API request failed: ' . $response->body());
        }

        $content = $response->json('message.content');
        $data = is_string($content) ? json_decode($content, true) : null;

        if (!$data || json_last_error() !== JSON_ERROR_NONE) {
            $raw = $response->body();
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && isset($parsed['message']['content']) && is_string($parsed['message']['content'])) {
                $data = json_decode($parsed['message']['content'], true);
            }
            if ((!$data || json_last_error() !== JSON_ERROR_NONE) && preg_match('/\{.*\}/s', is_string($content) ? $content : $raw, $matches)) {
                $data = json_decode($matches[0], true);
            }
            if (!$data || json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON from AI: ' . json_last_error_msg());
            }
        }

        return $data;
    }

    /**
     * Resolve alternate base URL (swap localhost with 127.0.0.1)
     */
    private function resolveAlternateBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim((string) $baseUrl, '/');
        if (str_contains($trimmed, 'localhost')) {
            return str_replace('localhost', '127.0.0.1', $trimmed);
        }
        return $trimmed;
    }

    /**
     * Validate and correct AI output using DslValidator and deterministic rules
     */
    private function validateAndCorrectAiOutput(array $aiResult, string $query, array $preAnalysis): array
    {
        // Ensure basic structure
        $result = [
            'entity' => $aiResult['entity'] ?? 'contacts',
            'filters' => $aiResult['filters'] ?? ['contact' => [], 'company' => []],
            'semantic_query' => $aiResult['semantic_query'] ?? null,
            'summary' => $aiResult['summary'] ?? 'Updated search filters based on your request.',
            'custom' => $aiResult['custom'] ?? [],
        ];

        // Ensure filter buckets exist
        if (!isset($result['filters']['contact'])) {
            $result['filters']['contact'] = [];
        }
        if (!isset($result['filters']['company'])) {
            $result['filters']['company'] = [];
        }

        // CRITICAL: Validate DSL and auto-correct misplaced filters
        $validation = DslValidator::validate($result['filters']);
        
        if (!$validation['valid']) {
            Log::warning('AI produced invalid DSL, auto-correcting', [
                'errors' => $validation['errors'],
                'original' => $result['filters'],
                'corrected' => $validation['normalized'],
            ]);
            
            $result['filters'] = $validation['normalized'];
        } else {
            $result['filters'] = $validation['normalized'];
        }

        // CRITICAL: Normalize job titles (longest wins)
        $result['filters'] = $this->normalizeJobTitles($result['filters']);

        // CRITICAL: Override entity detection with deterministic logic
        $result['entity'] = DslValidator::detectEntity($result['filters']);
        
        // If pre-analysis detected job titles but AI didn't, inject them
        if ($preAnalysis['has_job_title_keyword'] && empty($result['filters']['contact']['job_title'])) {
            if (!empty($preAnalysis['job_titles'])) {
                $bestJobTitle = $this->selectLongestJobTitle($preAnalysis['job_titles']);
                $result['filters']['contact']['job_title'] = ['include' => [Str::title($bestJobTitle)]];
                Log::info('Injected job title from deterministic analysis', ['job_title' => $bestJobTitle]);
            }
        }

        // If pre-analysis detected company names but AI didn't, inject them
        if (!empty($preAnalysis['company_names']) && empty($result['filters']['company']['company_names'])) {
            $result['filters']['company']['company_names'] = ['include' => array_map(fn($n) => Str::title((string) $n), $preAnalysis['company_names'])];
            Log::info('Injected company names from deterministic analysis', ['companies' => $preAnalysis['company_names']]);
        }
        if (!empty($preAnalysis['company_domains']) && empty($result['filters']['company']['company_domain'])) {
            $result['filters']['company']['company_domain'] = ['include' => array_values(array_unique($preAnalysis['company_domains']))];
            Log::info('Injected company domains from deterministic analysis', ['domains' => $preAnalysis['company_domains']]);
        }

        // If pre-analysis detected locations but AI didn't, inject into contact bucket
        $loc = $preAnalysis['locations'] ?? ['countries' => [], 'states' => [], 'cities' => []];
        $hasAnyLoc = (is_array($loc['countries']) && count($loc['countries']) > 0) || (is_array($loc['states']) && count($loc['states']) > 0) || (is_array($loc['cities']) && count($loc['cities']) > 0);
        if ($hasAnyLoc) {
            // Prefer contact-level location for mixed queries
            if (empty($result['filters']['contact']['countries']) && !empty($loc['countries'])) {
                $result['filters']['contact']['countries'] = ['include' => array_map(fn($c) => Str::title((string) $c), array_values(array_unique($loc['countries'])) )];
            }
            if (empty($result['filters']['contact']['states']) && !empty($loc['states'])) {
                $result['filters']['contact']['states'] = ['include' => array_map(fn($s) => Str::title((string) $s), array_values(array_unique($loc['states'])) )];
            }
            if (empty($result['filters']['contact']['cities']) && !empty($loc['cities'])) {
                $result['filters']['contact']['cities'] = ['include' => array_map(fn($ci) => Str::title((string) $ci), array_values(array_unique($loc['cities'])) )];
            }
        }

        // Parse revenue hints from query and inject as company.annual_revenue.range
        $minRevenue = $this->parseRevenueMin($query);
        if ($minRevenue !== null) {
            if (!isset($result['filters']['company']['annual_revenue']) || !is_array($result['filters']['company']['annual_revenue'])) {
                $result['filters']['company']['annual_revenue'] = [];
            }
            $existingRange = (array) ($result['filters']['company']['annual_revenue']['range'] ?? []);
            $existingRange['min'] = $existingRange['min'] ?? $minRevenue;
            $result['filters']['company']['annual_revenue']['range'] = $existingRange;
        }

        // Parse technology keywords (e.g., Selenium) and inject as company.technologies.include
        $techs = $this->parseTechnologies($query);
        if (!empty($techs)) {
            $existing = (array) ($result['filters']['company']['technologies']['include'] ?? []);
            $result['filters']['company']['technologies']['include'] = array_values(array_unique(array_merge($existing, $techs)));
        }

        return $result;
    }

    /**
     * Normalize job titles: if multiple exist, keep only the longest/most specific
     */
    private function normalizeJobTitles(array $filters): array
    {
        if (!isset($filters['contact']['job_title'])) {
            return $filters;
        }

        $jobTitleFilter = $filters['contact']['job_title'];
        
        // Handle different structures
        if (isset($jobTitleFilter['include']) && is_array($jobTitleFilter['include'])) {
            $titles = $jobTitleFilter['include'];
        } elseif (is_array($jobTitleFilter)) {
            $titles = array_values($jobTitleFilter);
        } else {
            return $filters;
        }

        if (count($titles) > 1) {
            $bestTitle = $this->selectLongestJobTitle($titles);
            $filters['contact']['job_title'] = ['include' => [$bestTitle]];
            
            Log::info('Normalized multiple job titles to longest', [
                'original_titles' => $titles,
                'selected_title' => $bestTitle,
            ]);
        }

        return $filters;
    }

    /**
     * Select the longest/most specific job title
     */
    private function selectLongestJobTitle(array $titles): string
    {
        if (empty($titles)) {
            return '';
        }

        // Sort by length descending
        usort($titles, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        return trim($titles[0]);
    }

    /**
     * Parse minimum revenue from free text (supports K/M/B and words)
     */
    private function parseRevenueMin(string $text): ?int
    {
        $t = strtolower($text);
        // Patterns: $20M, 20M, 20 million, 20000000
        if (preg_match('/\$?\s*(\d{1,3}(?:[,\.]\d{3})*|\d+(?:\.\d+)?)\s*(b|m|k|million|billion|thousand)?/i', $t, $m)) {
            $numStr = str_replace([','], '', $m[1]);
            $num = (float) $numStr;
            $unit = strtolower($m[2] ?? '');
            $mult = 1;
            if ($unit === 'k' || $unit === 'thousand') $mult = 1_000;
            elseif ($unit === 'm' || $unit === 'million') $mult = 1_000_000;
            elseif ($unit === 'b' || $unit === 'billion') $mult = 1_000_000_000;
            $val = (int) round($num * $mult);
            if ($val > 0) return $val;
        }
        return null;
    }

    /**
     * Parse simple technology tokens from text
     */
    private function parseTechnologies(string $text): array
    {
        $out = [];
        if (preg_match('/\bselenium\b/i', $text)) {
            $out[] = 'Selenium';
        }
        return $out;
    }

    /**
     * Build rule-based response when AI is unavailable
     */
    private function buildRuleBasedResponse(string $query, array $preAnalysis): array
    {
        $filters = ['contact' => [], 'company' => []];

        // Job titles
        if (!empty($preAnalysis['job_titles'])) {
            $bestJobTitle = $this->selectLongestJobTitle($preAnalysis['job_titles']);
            $filters['contact']['job_title'] = ['include' => [\Illuminate\Support\Str::title($bestJobTitle)]];
        }

        // Company names/domains
        if (!empty($preAnalysis['company_names'])) {
            $filters['company']['company_names'] = ['include' => array_map(fn($n) => \Illuminate\Support\Str::title((string) $n), $preAnalysis['company_names'])];
        }
        if (!empty($preAnalysis['company_domains'])) {
            $filters['company']['company_domain'] = ['include' => array_values(array_unique($preAnalysis['company_domains']))];
        }

        // Technologies (e.g., Selenium)
        $techs = $this->parseTechnologies($query);
        if (!empty($techs)) {
            $filters['company']['technologies'] = ['include' => $techs];
        }

        // Revenue minimum
        $minRevenue = $this->parseRevenueMin($query);
        if ($minRevenue !== null) {
            $filters['company']['annual_revenue'] = [
                'range' => ['min' => $minRevenue]
            ];
        }

        // Locations heuristics into contact bucket (safer default)
        $loc = $preAnalysis['locations'] ?? ['countries' => [], 'states' => [], 'cities' => []];
        $hasAnyLoc = (is_array($loc['countries']) && count($loc['countries']) > 0) || (is_array($loc['states']) && count($loc['states']) > 0) || (is_array($loc['cities']) && count($loc['cities']) > 0);
        if ($hasAnyLoc) {
            if (!empty($loc['countries'])) $filters['contact']['countries'] = ['include' => array_map(fn($c) => \Illuminate\Support\Str::title((string) $c), array_values(array_unique($loc['countries'])) )];
            if (!empty($loc['states'])) $filters['contact']['states'] = ['include' => array_map(fn($s) => \Illuminate\Support\Str::title((string) $s), array_values(array_unique($loc['states'])) )];
            if (!empty($loc['cities'])) $filters['contact']['cities'] = ['include' => array_map(fn($ci) => \Illuminate\Support\Str::title((string) $ci), array_values(array_unique($loc['cities'])) )];
        }

        // If no specific filters detected, use keywords under companies
        if (empty($filters['contact']) && empty($filters['company'])) {
            $filters['company']['company_keywords'] = ['include' => [$query]];
        }

        // Determine entity based on filters
        $entity = DslValidator::detectEntity(['contact' => $filters['contact'], 'company' => $filters['company']]);

        return [
            'entity' => $entity,
            'filters' => $filters,
            'summary' => 'AI assistance is temporarily unavailable. Filters have been applied automatically. You can refine them manually.',
            'semantic_query' => null,
            'custom' => [],
            'fallback_mode' => true,
        ];
    }

    //
}
