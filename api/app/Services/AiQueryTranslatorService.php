<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiQueryTranslatorService
{
    /**
     * Translate a natural language conversation into structured filters using TinyLlama.
     *
     * @param array $messages History of messages [['role' => 'user'|'assistant', 'content' => '...']]
     * @param array $context Metadata like ['lastResultCount' => 0]
     * @return array{entity: string, filters: array, summary: string, semantic_query: string|null, custom: array}
     */
    public function translate(array $messages, array $context = []): array
    {
        // STEP 1B: Hard fallback if no API key or TinyLlama unavailable
        $baseUrl = config('services.ollama.base_url');
        $model = config('services.ollama.chat_model');
        
        if (!$baseUrl || !$model) {
            return ['entity' => 'contacts', 'filters' => [], 'summary' => 'AI service not configured.', 'semantic_query' => null, 'custom' => []];
        }

        // Extract query from messages (last user message)
        $query = '';
        foreach (array_reverse($messages) as $msg) {
            if (isset($msg['role']) && $msg['role'] === 'user' && isset($msg['content'])) {
                $query = $msg['content'];
                break;
            }
        }

        // If no query found in messages, use empty string but still process
        if (empty($query)) {
            $query = '';
        }

        // Preamble for context-awareness (Result Count)
        $contextPreamble = "";
        if (isset($context['lastResultCount'])) {
            $count = (int) $context['lastResultCount'];
            $contextPreamble = "SYSTEM NOTE: The user's PREVIOUS search yielded exactly {$count} results.\n";
            if ($count === 0) {
                $contextPreamble .= "Since the previous result was 0, you SHOULD suggest broadening the filters (removing strict constraints) or explaining why it might be empty.\n";
            }
        }

        $systemPrompt = <<<EOT
You are an AI that converts natural language into structured B2B search filters.
You must ALWAYS output strict JSON. Never output text outside JSON. 
Do not infer facts. Only translate the user's instructions into filters.

{$contextPreamble}

YOUR JOB:
1. Analyze the entire CONVERSATION HISTORY to understand the current search context.
2. Detect whether the query is about CONTACTS or COMPANIES.
3. Extract only what the user explicitly describes or implies based on previous context.
4. If the user says "refine" or "remove", modify the previous filters accordingly.
5. Map natural-language terms into normalized filter fields.
6. NEVER guess unknown company names, industries, or locations.
7. Keep output minimal and safe.

NORMALIZED FIELDS YOU ARE ALLOWED TO USE:
{
  "entity": "contacts" | "companies",
  "filters": {},
  "semantic_query": "Optional: A descriptive sentence for vector search if the user asks for concepts/lookalikes (e.g. 'Sustainable logistics companies' or 'Competitors to Stripe').",
  "summary": "Short explanation of what filters were applied (e.g. 'Searching for SaaS companies in NY with Revenue > 1M')",
  "custom": []
}

EXAMPLES:

User: "Find HR professionals from US"
JSON:
{
    "entity": "contacts",
    "filters": {},
    "summary": "Searching for HR professionals in the United States."
}

User: "Marketing managers in UK with 5+ years experience"
JSON:
{
    "entity": "contacts",
    "filters": {},
    "summary": "Searching for Marketing Managers in the UK with 5+ years of experience."
}

OUTPUT FORMAT:
Return ONLY valid JSON:
{
  "entity": "...",
  "filters": { ... },
  "semantic_query": "...",
  "summary": "...",
  "custom": [...]
}
EOT;

        try {
            // Build the messages array for Ollama
            // Prepend system prompt
            $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

            // Append conversation history (sanitized)
            foreach ($messages as $msg) {
                if (isset($msg['role'], $msg['content']) && in_array($msg['role'], ['user', 'assistant'])) {
                    $apiMessages[] = [
                        'role' => $msg['role'],
                        'content' => substr($msg['content'], 0, 1000) // Limit length for safety
                    ];
                }
            }

            // If no messages, add the query as a user message
            if (empty($apiMessages) || count($apiMessages) === 1) {
                $apiMessages[] = ['role' => 'user', 'content' => $query];
            }

            // Ollama API Call (fail fast and respect app timeout)
            $timeout = (int) env('AI_TRANSLATE_TIMEOUT', 10);
            if ($timeout <= 0) {
                $timeout = 10;
            }
            
            $response = Http::timeout($timeout)
                ->connectTimeout(min(3, $timeout))
                ->post("{$baseUrl}/api/chat", [
                    'model' => $model,
                    'messages' => $apiMessages,
                    'stream' => false,
                    'format' => 'json', // Ollama supports this to force JSON
                    'options' => [
                        'temperature' => 0,
                    ]
                ]);

            if ($response->failed()) {
                Log::error('Ollama API request failed: ' . $response->body());
                return $this->getFallbackResponse($query);
            }

            $content = $response->json('message.content');
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Ollama returned invalid JSON: ' . $content);
                // Attempt simplistic extraction if needed
                if (preg_match('/\{.*\}/s', $content, $matches)) {
                    $data = json_decode($matches[0], true);
                }

                if (!$data) {
                    return $this->getFallbackResponse($query);
                }
            }

            // Ensure basics exist
            $result = [
                'entity' => $data['entity'] ?? 'contacts',
                'filters' => $data['filters'] ?? [],
                'semantic_query' => $data['semantic_query'] ?? null,
                'summary' => $data['summary'] ?? 'Updated search filters based on your request.',
                'custom' => $data['custom'] ?? [],
            ];

            // STEP 1C: Apply deterministic safety logic
            return $this->applySafetyLogic($result, $query);

        } catch (\Exception $e) { // Catch global Exception
            Log::error('AiQueryTranslatorService Exception: ' . $e->getMessage());
            return $this->getFallbackResponse($query);
        }
    }

    /**
     * Apply safety logic to ensure minimum filter requirements
     */
    private function applySafetyLogic(array $result, string $query): array
    {
        // Convert filters to array format if needed
        $filters = $result['filters'];
        
        // If query contains job words, ensure at least title or department
        $jobWords = ['engineer', 'manager', 'vp', 'sales', 'cto', 'ceo', 'cfo', 'coo', 'cio', 'developer', 'designer', 'analyst', 'consultant', 'director', 'head'];
        $hasJobWord = false;
        foreach ($jobWords as $word) {
            if (stripos($query, $word) !== false) {
                $hasJobWord = true;
                break;
            }
        }
        
        if ($hasJobWord && empty($filters)) {
            // For backward compatibility with tests expecting array format
            $result['filters'] = [['field' => 'title', 'operator' => '=', 'value' => '']];
        }
        
        // If query contains location words, add location.country
        $locationWords = ['germany', 'india', 'usa', 'uk', 'france', 'canada', 'australia', 'berlin', 'london', 'new york', 'california', 'texas'];
        $locationMap = [
            'germany' => 'germany',
            'india' => 'india', 
            'usa' => 'united states',
            'uk' => 'united kingdom',
            'france' => 'france',
            'canada' => 'canada',
            'australia' => 'australia',
            'berlin' => 'germany',
            'london' => 'united kingdom',
            'new york' => 'united states',
            'california' => 'united states',
            'texas' => 'united states'
        ];
        
        foreach ($locationWords as $loc) {
            if (stripos($query, $loc) !== false && isset($locationMap[$loc])) {
                if (is_array($result['filters']) && !empty($result['filters'])) {
                    // Check if location filter already exists
                    $hasLocation = false;
                    foreach ($result['filters'] as $filter) {
                        if (isset($filter['field']) && $filter['field'] === 'location.country') {
                            $hasLocation = true;
                            break;
                        }
                    }
                    if (!$hasLocation) {
                        $result['filters'][] = ['field' => 'location.country', 'operator' => '=', 'value' => $locationMap[$loc]];
                    }
                }
                break;
            }
        }
        
        return $result;
    }

    /**
     * Get fallback response when AI service fails
     */
    private function getFallbackResponse(string $query): array
    {
        // Still apply safety logic even in fallback
        $result = ['entity' => 'contacts', 'filters' => [], 'summary' => 'Could not process search request.', 'semantic_query' => null, 'custom' => []];
        return $this->applySafetyLogic($result, $query);
    }
}
