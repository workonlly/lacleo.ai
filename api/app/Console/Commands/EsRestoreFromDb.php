<?php

namespace App\Console\Commands;

use App\Elasticsearch\ElasticClient;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Console\Command;
use Throwable;

class EsRestoreFromDb extends Command
{
    protected $signature = 'es:restore-from-db {target : contacts|companies} {--batch=1000} {--suffix=} {--alias=}';

    protected $description = 'Rebuild Elasticsearch index from database source of truth with bulk ingestion';

    public function handle(ElasticClient $elastic)
    {
        $this->error('Elasticsearch dynamic index restore is disabled by index contract');
        return 1;
    }
}
