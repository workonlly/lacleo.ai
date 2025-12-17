<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    protected string $apiKey;
    protected string $model = 'text-embedding-3-small';

    public function __construct()
    {
        $this->model = config('services.ollama.embedding_model');
    }

    /**
     * Generate an embedding vector for a given text.
     *
     * @param string $text
     * @return array
     * @throws Exception
     */
    public function generate(string $text): array
    {
        $baseUrl = config('services.ollama.base_url');

        // Remove newlines
        $text = str_replace("\n", " ", $text);

        $response = Http::timeout(30)
            ->post("{$baseUrl}/api/embeddings", [
                'model' => $this->model,
                'prompt' => $text, // Ollama uses 'prompt', OpenAI uses 'input'
            ]);

        if ($response->failed()) {
            Log::error('Ollama Embedding Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception("Failed to generate embedding: " . $response->body());
        }

        // Ollama returns { "embedding": [...] }
        return $response->json('embedding');
    }

    /**
     * Generate description for Company to be embedded.
     */
    public function getCompanyEmbeddingText(\App\Models\Company $company): string
    {
        // Enrich context with description, industry, and keywords
        $parts = [
            "Company: " . $company->company,
            "Industry: " . $company->industry,
            "Description: " . ($company->seoDescription ?? $company->businessDescription ?? ''),
            "Keywords: " . implode(", ", $company->keywords ?? []),
            "Services: " . $company->serviceProduct,
        ];
        return implode(". ", array_filter($parts));
    }

    /**
     * Generate description for Contact to be embedded.
     */
    public function getContactEmbeddingText(\App\Models\Contact $contact): string
    {
        // Enrich context
        $parts = [
            "Person: " . $contact->full_name,
            "Title: " . $contact->title,
            "History: " . $contact->company, // Context of where they work
            "Departments: " . implode(", ", $contact->departments ?? []),
        ];
        return implode(". ", array_filter($parts));
    }
}
