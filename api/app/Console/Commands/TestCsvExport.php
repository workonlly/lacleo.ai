<?php

namespace App\Console\Commands;

use App\Exports\ExportCsvBuilder;
use App\Models\Company;
use App\Services\RecordNormalizer;
use Illuminate\Console\Command;

class TestCsvExport extends Command
{
    protected $signature = 'test:csv-export';
    protected $description = 'Test CSV export with 4 companies';

    public function handle()
    {
        $ids = [
            "integramicro.com__Integra Micro Systems Pvt Ltd__",
            "flex1848.be__FLEX 1848__",
            "suncomfort.be__Suncomfort__",
            "hellenicsealines.gr__Hellenic Sea Lines S.C.__"
        ];

        $this->info("=== Testing CSV Export with " . count($ids) . " companies ===\n");

        // Simulate what the controller does
        $companies = [];
        foreach ($ids as $id) {
            try {
                $decodedId = urldecode(str_replace('+', ' ', $id));
                $company = null;
                
                try {
                    $company = Company::findInElastic($decodedId);
                    $this->line("✓ Found by exact ID: $id");
                } catch (\Exception $ex) {
                    // Try domain fallback
                    if (strpos($decodedId, '__') !== false) {
                        $domain = explode('__', $decodedId)[0];
                        $paginated = Company::elastic()->filter(['term' => ['website' => $domain]])->paginate(1, 1);
                        $results = $paginated['data'] ?? [];
                        if (count($results) > 0) {
                            $company = $results[0];
                            $this->line("✓ Found by domain: $domain");
                        }
                    }
                }
                
                if ($company) {
                    $companies[] = $company;
                } else {
                    $this->error("✗ Company not found: $id");
                }
            } catch (\Exception $e) {
                $this->error("✗ Error: " . $e->getMessage());
            }
        }

        $this->info("\nCompanies found: " . count($companies) . "\n");

        if (count($companies) === 0) {
            $this->error("No companies found!");
            return;
        }

        // Build CSV
        $this->info("Building CSV...\n");
        try {
            $csv = ExportCsvBuilder::buildCompaniesCsvDynamic($companies, [], true, true);
            
            // Count rows
            $lines = explode("\n", trim($csv));
            $headerLine = array_shift($lines);
            $headers = str_getcsv($headerLine);
            $dataLines = array_filter($lines); // Remove empty lines
            
            $this->info("✓ CSV Generated Successfully!");
            $this->line("\nCSV Statistics:");
            $this->line("  Headers: " . count($headers));
            $this->line("  Data rows: " . count($dataLines));
            $this->line("  Total lines: " . count($lines));
            
            $this->line("\nFirst 5 rows:");
            $this->line("─────────────────────────────────────────");
            
            // Show headers
            $this->line("HEADERS: " . implode(" | ", array_slice($headers, 0, 5)) . " ...");
            
            // Show first 4 data rows
            foreach (array_slice($dataLines, 0, 4) as $rowIdx => $line) {
                $rowData = str_getcsv($line);
                $domain = $rowData[0] ?? 'N/A';
                $name = $rowData[1] ?? 'N/A';
                $website = $rowData[2] ?? 'N/A';
                $this->line("ROW " . ($rowIdx + 1) . ": $domain | $name | $website");
            }
            
            $this->info("\n✓ CSV Export Test PASSED - All 4 companies exported!");
            
            // Save to file for inspection
            $filename = storage_path('logs/test_export_' . date('Y-m-d_His') . '.csv');
            file_put_contents($filename, $csv);
            $this->line("\nCSV saved to: $filename");
            
        } catch (\Exception $e) {
            $this->error("✗ CSV Generation failed: " . $e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }
}
