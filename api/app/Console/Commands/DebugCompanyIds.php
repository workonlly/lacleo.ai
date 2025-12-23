<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class DebugCompanyIds extends Command
{
    protected $signature = 'debug:company-ids';
    protected $description = 'Debug company ID lookup issues';

    public function handle()
    {
        $ids = [
            "integramicro.com__Integra Micro Systems Pvt Ltd__",
            "flex1848.be__FLEX 1848__",
            "suncomfort.be__Suncomfort__",
            "hellenicsealines.gr__Hellenic Sea Lines S.C.__"
        ];

        $this->info("=== Testing exact ID lookup ===\n");
        foreach ($ids as $id) {
            $this->line("Original ID: '$id' (length: " . strlen($id) . ")");
            $decodedId = urldecode($id);
            $this->line("Decoded ID: '$decodedId' (length: " . strlen($decodedId) . ")");
            try {
                $company = Company::findInElastic($decodedId);
                if ($company) {
                    $this->info("  ✓ FOUND: " . ($company->name ?? 'N/A'));
                } else {
                    $this->warn("  ✗ Not found (null returned)");
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Error: " . $e->getMessage());
            }
        }

        $this->info("\n=== Searching by domain only ===\n");
        $domains = ['integramicro.com', 'flex1848.be', 'suncomfort.be', 'hellenicsealines.gr'];
        foreach ($domains as $domain) {
            $this->line("Searching for domain: '$domain'");
            try {
                $builder = Company::elastic()->filter(['term' => ['website' => $domain]]);
                $results = $builder->paginate(1, 10);
                $companies = $results['data'] ?? [];
                
                if (count($companies) > 0) {
                    foreach ($companies as $company) {
                        $docId = $company['_id'] ?? 'unknown';
                        $name = $company['_source']['company'] ?? 'N/A';
                        $this->line("  Found ID: '$docId' | Name: '$name'");
                    }
                } else {
                    $this->warn("  No results found");
                }
            } catch (\Exception $e) {
                $this->error("  Error: " . $e->getMessage());
            }
        }
    }
}
