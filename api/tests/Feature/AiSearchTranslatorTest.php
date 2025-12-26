<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSearchTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mock Ollama config instead of OpenAI
        config([
            'services.ollama.base_url' => 'http://localhost:11434',
            'services.ollama.chat_model' => 'tinyllama',
        ]);
    }

    public function test_it_translates_natural_language_to_filters_using_tinyllama()
    {
        // Mock expected Ollama/TinyLlama response format
        Http::fake([
            'localhost:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'entity' => 'contacts',
                        'filters' => [],
                        'summary' => 'Searching for VP of Sales in London with revenue over 10M.',
                        'semantic_query' => null,
                        'custom' => []
                    ])
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/ai/translate-query', [
            'query' => 'VP of Sales in London with >10M revenue'
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'entity',
                'filters',
                'summary',
                'semantic_query',
                'custom'
            ])
            ->assertJson([
                'entity' => 'contacts',
            ]);

        // Assert that the correct Ollama endpoint was called
        Http::assertSent(function ($request) {
            return $request->url() == 'http://localhost:11434/api/chat' &&
                $request['model'] == 'tinyllama' &&
                $request['format'] == 'json' &&
                str_contains($request['messages'][1]['content'] ?? '', 'VP of Sales');
        });
    }

    public function test_it_handles_ollama_failure_gracefully()
    {
        Http::fake([
            'localhost:11434/api/chat' => Http::response('Server Error', 500),
        ]);

        $response = $this->postJson('/api/v1/ai/translate-query', [
            'query' => 'Find something'
        ]);

        // Expect empty fallback but successful 200 OK response from our API
        $response->assertStatus(200)
            ->assertJsonStructure([
                'entity',
                'filters',
                'summary',
                'semantic_query',
                'custom'
            ]);
    }

    public function test_it_respects_missing_ollama_config()
    {
        // Clear Ollama config
        config([
            'services.ollama.base_url' => null,
            'services.ollama.chat_model' => null,
        ]);

        // Should return empty immediately without calling Http
        Http::fake();

        $response = $this->postJson('/api/v1/ai/translate-query', [
            'query' => 'Find something'
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'entity',
                'filters',
                'summary',
                'semantic_query',
                'custom'
            ]);

        Http::assertNothingSent();
    }

    public function test_it_adds_safety_logic_for_job_and_location_keywords()
    {
        // Mock empty Ollama response to test safety logic
        Http::fake([
            'localhost:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'entity' => 'contacts',
                        'filters' => [],
                        'summary' => 'Test summary',
                        'semantic_query' => null,
                        'custom' => []
                    ])
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/ai/translate-query', [
            'query' => 'engineer in germany'
        ]);

        $response->assertStatus(200);
        
        $filters = $response->json('filters');
        $this->assertIsArray($filters);
        $this->assertArrayHasKey('job_title', $filters);
        $this->assertArrayHasKey('include', $filters['job_title']);
        $this->assertContains('Engineer', $filters['job_title']['include']);
        $this->assertArrayHasKey('location', $filters);
        $this->assertArrayHasKey('include', $filters['location']);
        $this->assertArrayHasKey('countries', $filters['location']['include']);
        $this->assertContains('Germany', $filters['location']['include']['countries']);
    }
}
