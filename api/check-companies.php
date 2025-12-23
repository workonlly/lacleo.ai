<?php

require 'vendor/autoload.php';

// Load Laravel app
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Company;
use Illuminate\Support\Facades\Log;

$ids = [
    'integramicro.com__Integra Micro Systems Pvt Ltd__',
    'flex1848.be__FLEX 1848__',
    'suncomfort.be__Suncomfort__',
    'hellenicsealines.gr__Hellenic Sea Lines S.C.__'
];

echo "Checking which company IDs exist in Elasticsearch...\n\n";

foreach ($ids as $id) {
    try {
        $company = Company::findInElastic($id);
        if ($company) {
            echo "✓ Found: $id\n";
            echo "  Name: " . ($company->company ?? $company->name ?? 'N/A') . "\n";
        } else {
            echo "✗ Not found: $id\n";
        }
    } catch (\Exception $e) {
        echo "✗ Error finding $id: " . $e->getMessage() . "\n";
    }
}

echo "\n";
echo "Now searching for these companies by domain/name in Elasticsearch...\n\n";

$instance = new Company();
$domains = ['integramicro.com', 'flex1848.be', 'suncomfort.be', 'hellenicsealines.gr'];

foreach ($domains as $domain) {
    try {
        $builder = Company::elastic();
        $results = $builder->filter(['term' => ['domain' => $domain]])->get();
        if (count($results) > 0) {
            echo "✓ Found companies with domain: $domain\n";
            foreach ($results as $comp) {
                echo "  ID: " . ($comp->elasticMetadata['id'] ?? 'unknown') . "\n";
                echo "  Name: " . ($comp->company ?? $comp->name ?? 'N/A') . "\n";
            }
        } else {
            echo "✗ No companies found with domain: $domain\n";
        }
    } catch (\Exception $e) {
        echo "✗ Error searching domain $domain: " . $e->getMessage() . "\n";
    }
}
