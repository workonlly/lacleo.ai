<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiQueryTranslatorService
{
    /**
     * Translate a natural language query into structured filters using OpenAI.
     *
     * @param string $query
     * @return array{entity: string, filters: array}
     */
    /**
     * Translate a natural language conversation into structured filters using OpenAI.
     *
     * @param array $messages History of messages [['role' => 'user'|'assistant', 'content' => '...']]
     * @param array $context Metadata like ['lastResultCount' => 0]
     * @return array{entity: string, filters: array, summary: string}
     */
    public function translate(array $messages, array $context = []): array
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

  "filters": {
    "job_title": { "include": [string], "exclude": [string] },      
    "departments": { "include": [string], "exclude": [string] },    
    "seniority": { "include": [string], "exclude": [string] },      
    "company_names": { "include": [string], "exclude": [string] },  
    "employee_count": { "min": num, "max": num }, 
    "revenue": { "min": num, "max": num },     
    "locations": { "include": [string], "exclude": [string] },      
    "technologies": { "include": [string], "exclude": [string] },   
    "industries": { "include": [string], "exclude": [string] },     
    "company_keywords": { "include": [string], "exclude": [string] },
    "years_of_experience": { "min": num, "max": num }
  },
  "semantic_query": "Optional: A descriptive sentence for vector search if the user asks for concepts/lookalikes (e.g. 'Sustainable logistics companies' or 'Competitors to Stripe').",
  "summary": "Short explanation of what filters were applied (e.g. 'Searching for SaaS companies in NY with Revenue > 1M')",
  "custom": [
    { "label": "string (e.g. Niche)", "value": "string (e.g. Bio-Tech)", "type": "custom" }
  ]
}

EXAMPLES:

User: "Find HR professionals from US"
JSON:
{
    "entity": "contacts",
    "filters": {
        "departments": { "include": ["Human Resources"] },
        "locations": { "include": ["United States"] }
    },
    "summary": "Searching for HR professionals in the United States."
}

User: "Find HR professionals with more than 10 years of experience"
JSON:
{
    "entity": "contacts",
    "filters": {
        "departments": { "include": ["Human Resources"] },
        "years_of_experience": { "min": 10 }
    },
    "summary": "Searching for HR professionals with 10+ years of experience."
}

User: "Find software engineers of companies with 500 plus employees"
JSON:
{
    "entity": "contacts",
    "filters": {
        "job_title": { "include": ["Software Engineer"] },
        "employee_count": { "min": 500 }
    },
    "summary": "Searching for software engineers at companies with 500+ employees."
}

User: "Companies with revenue below 10 million and above 1 million"
JSON:
{
    "entity": "companies",
    "filters": {
        "revenue": { "min": 1000000, "max": 10000000 }
    },
    "summary": "Searching for companies with revenue between \$1M and \$10M."
}

User: "Marketing managers in UK with 5+ years experience"
JSON:
{
    "entity": "contacts",
    "filters": {
        "job_title": { "include": ["Marketing Manager"] },
        "locations": { "include": ["United Kingdom"] },
        "years_of_experience": { "min": 5 }
    },
    "summary": "Searching for Marketing Managers in the UK with 5+ years of experience."
}

User: "Find bootstrapped biotech startups in SF"
JSON:
{
    "entity": "companies",
    "filters": {
        "locations": { "include": ["San Francisco"] }
    },
    "custom": [
        { "label": "Status", "value": "Bootstrapped", "type": "custom" },
        { "label": "Industry", "value": "Biotech", "type": "custom" }
    ],
    "summary": "Searching for bootstrapped biotech companies in San Francisco."
}

INTERPRETATION RULES:
1. **ENTITY**: "companies" or "contacts".
2. **FILTERS**: Use only standard keys if possible (locations, job_title, etc.).
3. **DYNAMIC / CUSTOM**: If a requirement doesn't fit standard filters (e.g. "Bootstrapped", "YC Backed", "Crypto"), PUT IT IN `custom`.
4. **OUTPUT**: JSON ONLY. No markdown.
- If the newest message changes the topic entirely, reset the filters.
- If the newest message is a refinement (e.g. "also in Texas", "remove managers"), merge strict logic with previous valid filters.
- **CRITICAL**: Use "company_keywords" for ANY topics, themes, business models, or context matching (e.g. "CRM", "Marketplace", "B2B", "Conferences", "Events").
- **DYNAMIC FILTERS**: If the user asks for a filter that doesn't map to a standard field but is important context (e.g., "Must be bootstrapped", "Founded by women"), put it in the `custom` array.
- **SEMANTIC SEARCH**: If the user asks for "Companies like [Company]" or "Startups in [Niche]", generate a `semantic_query` describing the ideal target.
- If the user asks for something unsupported (e.g. "last 6 months", "attended event"), **IGNORE** the constraint but **EXTRACT** the topic into "company_keywords".

SYNONYM MAPPING (expand common terms):
  HR → Human Resources
  IT → Information Technology
  Finance → Finance & Accounting
  Marketing → Marketing & Communications
  Sales → Sales & Business Development
  AI Engineer → Machine Learning Engineer
  Software Engineer → Developer, Programmer, Software Developer
  DevOps → DevOps Engineer, Site Reliability Engineer

LOCATION NORMALIZATION:
  US/USA → United States
  UK → United Kingdom
  UAE → United Arab Emirates
  SF/San Fran → San Francisco
  NYC/New York City → New York
  LA → Los Angeles

REVENUE/SIZE PARSING:
  1M = 1000000
  10M = 10000000
  1B = 1000000000
  500+ employees = {"min": 500}
  below 10M = {"max": 10000000}
  above 1M = {"min": 1000000}
  between 1M and 10M = {"min": 1000000, "max": 10000000}

EXPERIENCE PARSING:
  "more than 10 years" = {"min": 10}
  "5+ years" = {"min": 5}
  "less than 3 years" = {"max": 3}
  "between 5 and 10 years" = {"min": 5, "max": 10}

STRICT SAFETY RULES:
- DO NOT hallucinate company names.
- DO NOT add fields the user did not mention.
- DO NOT infer revenue, size, or experience unless explicitly stated.
- **PREFER** extracting keywords over returning empty filters. If a term is significant, put it in `company_keywords`.

OUTPUT FORMAT:
Return ONLY valid JSON:
{
  "entity": "...",
  "filters": { ... },
  "semantic_query": "...",
  "summary": "..."
}
EOT;

        try {
            $baseUrl = config('services.ollama.base_url');
            $model = config('services.ollama.chat_model');

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
                return ['entity' => 'contacts', 'filters' => [], 'summary' => 'Failed to process search request.'];
            }

            $content = $response->json('message.content');
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Ollama returned invalid JSON: ' . $content);
                // TinyLlama might output text before JSON despite instructions, attempt simplistic extraction if needed
                if (preg_match('/\{.*\}/s', $content, $matches)) {
                    $data = json_decode($matches[0], true);
                }

                if (!$data) {
                    return ['entity' => 'contacts', 'filters' => [], 'summary' => 'Could not understand the AI response.'];
                }
            }

            // Ensure basics exist
            return [
                'entity' => $data['entity'] ?? 'contacts',
                'filters' => $data['filters'] ?? [],
                'semantic_query' => $data['semantic_query'] ?? null,
                'summary' => $data['summary'] ?? 'Updated search filters based on your request.',
                'custom' => $data['custom'] ?? [],
            ];

        } catch (\Exception $e) { // Catch global Exception
            Log::error('AiQueryTranslatorService Exception: ' . $e->getMessage());
            return ['entity' => 'contacts', 'filters' => [], 'summary' => 'An error occurred while processing your request.'];
        }
    }
}
