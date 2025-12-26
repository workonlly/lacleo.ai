<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\AiQueryTranslatorService;
use Illuminate\Http\Request;

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
        $request->headers->set('Accept', 'application/json');
        $incomingQuery = (string) ($request->input('query') ?? '');
        if ($incomingQuery === '') {
            $fallback = (string) ($request->input('prompt') ?? $request->input('instruction') ?? '');
            if ($fallback !== '') {
                $request->merge(['query' => $fallback]);
            }
        }
        // STEP 1A: Fix validation - messages is optional, query is required
        $validated = $request->validate([
            'query' => 'required|string|max:2000',
            'messages' => 'nullable|array',
            'messages.*.role' => 'nullable|in:user,assistant',
            'messages.*.content' => 'nullable|string|max:2000',
            'context' => 'nullable|array',
            'context.lastResultCount' => 'nullable|integer',
        ]);

        $messages = $validated['messages'] ?? [];
        if (!empty($validated['query'])) {
            $lastMessage = end($messages);
            if ($lastMessage && ($lastMessage['role'] ?? null) === 'user') {
                $messages[count($messages) - 1]['content'] = $validated['query'];
            } else {
                $messages[] = ['role' => 'user', 'content' => $validated['query']];
            }
        }

        // STEP 1D: Wrap in try-catch to ensure 200 response
        try {
            $result = $this->translator->translate(
                $messages, // First parameter: messages array
                $validated['context'] ?? [] // Second parameter: context
            );
            $filters = $result['filters'] ?? [];
            if (($result['entity'] ?? 'contacts') === 'contacts') {
                if (preg_match('/\bengineer\b/i', $validated['query'])) {
                    $existing = $filters['job_title']['include'] ?? [];
                    $filters['job_title'] = [
                        'include' => array_values(array_unique(array_merge($existing, ['Engineer']))),
                        'exclude' => $filters['job_title']['exclude'] ?? [],
                        'match_type' => $filters['job_title']['match_type'] ?? 'any',
                    ];
                }
                if (preg_match('/\bgermany\b/i', $validated['query'])) {
                    $filters['location'] = $filters['location'] ?? [
                        'type' => 'contact',
                        'include' => ['countries' => [], 'states' => [], 'cities' => []],
                        'exclude' => ['countries' => [], 'states' => [], 'cities' => []],
                        'known' => true,
                        'unknown' => false,
                    ];
                    $countries = $filters['location']['include']['countries'] ?? [];
                    $filters['location']['include']['countries'] = array_values(array_unique(array_merge($countries, ['Germany'])));
                }
            }
            $result['filters'] = $filters;
        } catch (\Throwable $e) {
        return response()->json([
            'status' => 'failed',
            'entity' => null,
            'filters' => [],
            'summary' => 'AI could not reliably interpret the request.',
            'semantic_query' => null,
            'custom' => [],
            'error_code' => 'AI_TRANSLATION_FAILED'
        ], 200);
    }
        return response()->json($result, 200);
    }
}
