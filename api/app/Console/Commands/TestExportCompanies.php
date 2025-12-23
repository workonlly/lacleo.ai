<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\RecordNormalizer;
use Illuminate\Console\Command;

class TestExportCompanies extends Command
{
    protected $signature = 'test:export-companies';
    protected $description = 'Simulate an export request with 4 companies';

    public function handle()
    {
        $ids = [
            "integramicro.com__Integra Micro Systems Pvt Ltd__",
            "flex1848.be__FLEX 1848__",
            "suncomfort.be__Suncomfort__",
            "hellenicsealines.gr__Hellenic Sea Lines S.C.__"
        ];

        $this->info("Testing export with " . count($ids) . " companies\n");

        $companies = array_map(function ($id) {
            $this->line("Processing: '$id'");
            try {
                // URL-decode the ID
                $decodedId = urldecode($id);
                $company = null;
                
                try {
                    $company = Company::findInElastic($decodedId);
                    $this->info("  ✓ Found by exact ID");
                } catch (\Exception $lookupEx) {
                    $this->warn("  ✗ Not found by exact ID, trying domain fallback");
                    
                    // Try domain fallback
                    if (strpos($decodedId, '__') !== false) {
                        $domain = explode('__', $decodedId)[0];
                        $this->line("    Searching by domain: '$domain'");
                        $paginated = Company::elastic()->filter(['term' => ['website' => $domain]])->paginate(1, 1);
                        $results = $paginated['data'] ?? [];
                        if (count($results) > 0) {
                            $company = $results[0];
                            $this->info("    ✓ Found by domain fallback");
                        } else {
                            $this->error("    ✗ Not found by domain");
                        }
                    }
                }
                
                return $company;
            } catch (\Exception $e) {
                $this->error("  Fatal error: " . $e->getMessage());
                return null;
            }
        }, $ids);

        $companies = array_values(array_filter($companies));
        $this->info("\nResults: " . count($companies) . " companies found\n");

        foreach ($companies as $idx => $company) {
            $name = $company->company ?? $company->name ?? 'Unknown';
            $domain = $company->website ?? $company->domain ?? 'Unknown';
            $this->line("[$idx] $name ($domain)");
        }

        // Now normalize and build CSV
        $companiesNorm = array_map(function ($c) {
            return $c ? RecordNormalizer::normalizeCompany(is_array($c) ? $c : $c->toArray()) : null;
        }, $companies);
        $companiesNorm = array_values(array_filter($companiesNorm));

        $this->info("\nNormalized: " . count($companiesNorm) . " companies");
        
        // Show CSV headers
        if (count($companiesNorm) > 0) {
            $headers = ['domain', 'name', 'website', 'number_of_employees', 'industry', 'linkedin_url', 'facebook_url', 'twitter_url', 'street', 'city', 'state', 'country', 'postal_code', 'address', 'phone_number', 'keywords', 'technologies', 'total_funding_usd', 'annual_revenue_usd', 'sic_code', 'short_description', 'founded_year'];
            $this->line("\nCSV Headers (" . count($headers) . "):");
            $this->line(implode(", ", $headers));
            
            $this->line("\nFirst row data:");
            $row = $companiesNorm[0];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                if (is_array($value)) {
                    $value = implode('; ', $value);
                }
                $this->line("  $header: $value");
            }
        }
    }
}
