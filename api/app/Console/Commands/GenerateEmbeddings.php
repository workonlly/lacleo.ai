<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Contact;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;

class GenerateEmbeddings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'search:generate-embeddings {type? : company or contact} {--limit=100}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate embeddings for records that are missing them';

    /**
     * Execute the console command.
     */
    public function handle(EmbeddingService $embeddingService)
    {
        $type = $this->argument('type');
        $limit = $this->option('limit');

        if (!$type) {
            $type = $this->choice('Which entity?', ['company', 'contact'], 0);
        }

        $this->info("Generating embeddings for $type (Limit: $limit)...");

        $modelClass = $type === 'company' ? Company::class : Contact::class;

        // Find records. For now, we process all, or we could add a `whereNull('embedding')` if we had that column in SQL.
        // Since we only store in Elastic, we might just loop through SQL records and update Elastic.
        // NOTE: Ideally we should check if 'embedding' exists in Elastic, but for simplicity we iterate DB.

        // Use newQuery() on an instance to bypass HasElasticIndex::query() override
        $query = (new $modelClass)->newQuery();

        $count = 0;

        $query->chunk(50, function ($records) use ($embeddingService, $type, $limit, &$count, $modelClass) {
            foreach ($records as $record) {
                if ($count >= $limit)
                    return false;

                try {
                    $text = $type === 'company'
                        ? $embeddingService->getCompanyEmbeddingText($record)
                        : $embeddingService->getContactEmbeddingText($record);

                    if (strlen($text) < 10) {
                        $this->warn("Skipping {$record->id} - text too short");
                        continue;
                    }

                    $embedding = $embeddingService->generate($text);

                    // Update Elastic Document
                    // We use saveToElastic which regenerates the whole doc including the new embedding
                    // But we need to inject the embedding into the object first.
                    // Since 'embedding' isn't a DB column, we add it dynamically.

                    $record->embedding = $embedding;
                    // We need to ensure 'embedding' is included in toElasticArray()
                    // The trait filters by mapping, so since we added 'embedding' to mapping, it should work 
                    // IF it is in the model's attributes.

                    $record->saveToElastic();

                    $this->info("Processed {$record->id}");
                    $count++;

                } catch (\Exception $e) {
                    $this->error("Failed {$record->id}: " . $e->getMessage());
                }
            }
        });

        $this->info("Done. Processed $count records.");
    }
}
