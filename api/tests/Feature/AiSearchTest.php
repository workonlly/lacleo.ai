<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\AiQueryTranslatorService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class AiSearchTest extends TestCase
{
    public function test_translates_query_with_custom_filters()
    {
        // Mock Ollama response with custom field
        Http::fake([
            '*' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'entity' => 'contacts',
                        'filters' => [],
                        'summary' => 'Looking for bootstrapped bio-tech startups.',
                        'custom' => [
                            ['label' => 'Bootstrapped', 'value' => 'Bootstrapped', 'type' => 'custom'],
                            ['label' => 'Industry', 'value' => 'Bio-Tech', 'type' => 'custom']
                        ]
                    ])
                ]
            ], 200),
        ]);

        Config::set('services.ollama.base_url', 'http://localhost:11434');
        Config::set('services.ollama.chat_model', 'tinyllama');

        $service = app(AiQueryTranslatorService::class);
        $result = $service->translate([
            ['role' => 'user', 'content' => 'Find bootstrapped Bio-Tech startups']
        ]);

        $this->assertArrayHasKey('custom', $result);
        $this->assertCount(2, $result['custom']);
        $this->assertEquals('Bootstrapped', $result['custom'][0]['value']);
    }
}
