<?php

namespace App\Console\Commands;

use App\Elasticsearch\ElasticClient;
use Illuminate\Console\Command;

class ElasticHealthCheck extends Command
{
    protected $signature = 'elastic:health-check';

    protected $description = 'Validate aliases, delete-blocks, snapshots, doc counts, and mapping alignment';

    public function handle(ElasticClient $elastic): int
    {
        $client = $elastic->getClient();
        $aliases = [
            env('ELASTIC_CONTACT_INDEX'),
            env('ELASTIC_COMPANY_INDEX'),
        ];

        foreach ($aliases as $alias) {
            if (! $alias) {
                continue;
            }
            $this->info("Checking alias: {$alias}");
            $exists = $client->indices()->existsAlias(['name' => $alias])->asBool();
            $this->line(' - alias exists: '.($exists ? 'yes' : 'no'));
            $indices = $exists ? array_keys($client->indices()->getAlias(['name' => $alias])->asArray()) : [];
            foreach ($indices as $idx) {
                $count = $client->count(['index' => $idx])->asArray()['count'] ?? 0;
                $this->line("   * index={$idx} count={$count}");
                $settings = $client->indices()->getSettings(['index' => $idx])->asArray();
                $deleteBlocked = data_get($settings, "{$idx}.settings.index.blocks.delete") === 'true';
                $this->line('   * delete-block: '.($deleteBlocked ? 'true' : 'false'));
                try {
                    $mapping = $client->indices()->getMapping(['index' => $idx])->asArray();
                    $props = data_get($mapping, "{$idx}.mappings.properties");
                    $this->line('   * properties: '.(is_array($props) ? count($props) : 0));
                } catch (\Exception $e) {
                    $this->line('   * mapping: (error fetching)');
                }
            }

            // Also check if the provided name is an index (not alias)
            try {
                $isIndex = $client->indices()->exists(['index' => $alias])->asBool();
                $this->line(' - index exists: '.($isIndex ? 'yes' : 'no'));
                if ($isIndex) {
                    $count = $client->count(['index' => $alias])->asArray()['count'] ?? 0;
                    $this->line("   * index={$alias} count={$count}");
                    $settings = $client->indices()->getSettings(['index' => $alias])->asArray();
                    $deleteBlocked = data_get($settings, "{$alias}.settings.index.blocks.delete") === 'true';
                    $this->line('   * delete-block: '.($deleteBlocked ? 'true' : 'false'));
                    try {
                        $mapping = $client->indices()->getMapping(['index' => $alias])->asArray();
                        $props = data_get($mapping, "{$alias}.mappings.properties");
                        $this->line('   * properties: '.(is_array($props) ? count($props) : 0));
                    } catch (\Exception $e) {
                        $this->line('   * mapping: (error fetching)');
                    }
                }
            } catch (\Exception $e) {
                $this->line(' - index exists: (error checking)');
            }
        }

        $this->info('Snapshot repositories:');
        try {
            $repos = $client->cat()->repositories()->asString();
            $this->line($repos ?: '(none)');
        } catch (\Exception $e) {
            $this->line('(cat repositories failed)');
        }

        $this->info('Health check completed');

        return 0;
    }
}
