<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\Config;

class OllamaIntegrationTest extends TestCase
{
    /**
     * Test if Ollama generates embeddings with correct dimensions (768).
     */
    public function test_ollama_generates_768_dim_embeddings()
    {
        // Ensure config is set to local ollama
        Config::set('services.ollama.base_url', 'http://localhost:11434');
        Config::set('services.ollama.embedding_model', 'nomic-embed-text');

        $service = new EmbeddingService();

        try {
            $embedding = $service->generate("Test company description for vector search.");

            $this->assertIsArray($embedding, "Embedding should be an array");
            $this->assertCount(768, $embedding, "Embedding should have 768 dimensions (nomic-embed-text)");
            $this->assertIsFloat($embedding[0], "Embedding elements should be floats");

            echo "\n✅ Ollama Integration Verified: Received 768-dim vector.\n";

        } catch (\Exception $e) {
            $this->fail("Ollama Connection Failed: " . $e->getMessage() . "\nMake sure 'ollama serve' is running and you pulled 'nomic-embed-text'.");
        }
    }
}
