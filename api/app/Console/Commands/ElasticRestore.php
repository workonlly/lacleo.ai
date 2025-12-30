<?php

namespace App\Console\Commands;

use App\Elasticsearch\ElasticClient;
use Illuminate\Console\Command;

class ElasticRestore extends Command
{
    protected $signature = 'elastic:restore {repo} {snapshot} {--contacts=} {--companies=} {--suffix=_restore} {--alias=}';

    protected $description = 'Restore indices from a snapshot into safe targets and optionally set aliases';

    public function handle(ElasticClient $elastic): int
    {
        $client = $elastic->getClient();
        $repo = $this->argument('repo');
        $snapshot = $this->argument('snapshot');
        $suffix = $this->option('suffix') ?? '_restore';
        $contacts = $this->option('contacts') ?: env('ELASTIC_CONTACT_INDEX');
        $companies = $this->option('companies') ?: env('ELASTIC_COMPANY_INDEX');

        $targets = [];
        foreach (array_filter([$contacts, $companies]) as $src) {
            $targets[$src] = $src.$suffix;
        }

        foreach ($targets as $src => $dst) {
            if ($client->indices()->exists(['index' => $dst])->asBool()) {
                $this->warn("Target index already exists: {$dst}");
            }
        }

        $this->info("Restoring snapshot {$repo}/{$snapshot}...");
        $client->snapshot()->restore([
            'repository' => $repo,
            'snapshot' => $snapshot,
            'body' => [
                'indices' => implode(',', array_keys($targets)),
                'include_global_state' => false,
                'rename_pattern' => '(.+)',
                'rename_replacement' => '$1'.$suffix,
            ],
        ]);

        $this->info('Waiting for indices to be ready...');
        foreach ($targets as $src => $dst) {
            $client->cluster()->health(['index' => $dst, 'wait_for_status' => 'yellow']);
        }

        foreach ($targets as $src => $dst) {
            $count = $client->count(['index' => $dst])->asArray()['count'] ?? 0;
            $this->info("Index {$dst} count: {$count}");
        }

        if ($alias = $this->option('alias')) {
            $this->info("Setting alias {$alias} to restored indices (contacts only if provided)");
            $client->indices()->updateAliases([
                'body' => [
                    'actions' => [
                        ['add' => ['index' => $contacts.$suffix, 'alias' => $alias]],
                    ],
                ],
            ]);
        }

        $this->info('Restore completed');

        return 0;
    }
}
