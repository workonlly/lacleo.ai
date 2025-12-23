<?php
require 'vendor/autoload.php';

$elasticClient = app(\Elastica\Client::class);
$index = $elasticClient->getIndex('stage_lacleo_company_stats');

$selectedIds = [
    "integramicro.com__Integra Micro Systems Pvt Ltd__",
    "flex1848.be__FLEX 1848__",
    "suncomfort.be__Suncomfort__",
    "hellenicsealines.gr__Hellenic Sea Lines S.C.__"
];

echo "=== Testing exact ID lookup ===\n";
foreach ($selectedIds as $id) {
    echo "\n\nTrying to fetch ID: '$id'\n";
    echo "ID length: " . strlen($id) . "\n";
    echo "ID bytes: " . bin2hex($id) . "\n";
    
    try {
        $doc = $elasticClient->getDocument('stage_lacleo_company_stats', $id);
        if ($doc && isset($doc['_source'])) {
            echo "✓ FOUND via exact ID!\n";
            echo "  Company Name: " . ($doc['_source']['company'] ?? 'N/A') . "\n";
            echo "  Website: " . ($doc['_source']['website'] ?? 'N/A') . "\n";
        } else {
            echo "✗ Document not found or no source\n";
        }
    } catch (\Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n\n=== Searching by domain and extracting exact IDs ===\n";
$domains = ['integramicro.com', 'flex1848.be', 'suncomfort.be', 'hellenicsealines.gr'];

foreach ($domains as $domain) {
    echo "\n\nSearching for domain: $domain\n";
    
    $query = new \Elastica\Query\Match('website', $domain);
    $search = $index->search($query);
    $results = $search->getResults();
    
    if (count($results) > 0) {
        foreach ($results as $result) {
            $docId = $result->getId();
            $source = $result->getSource();
            echo "  Found: ID='$docId' | Company='" . ($source['company'] ?? 'N/A') . "'\n";
            echo "    ID length: " . strlen($docId) . "\n";
            echo "    Trying to fetch this exact ID...\n";
            
            try {
                $doc = $elasticClient->getDocument('stage_lacleo_company_stats', $docId);
                echo "    ✓ Can fetch by exact ID\n";
            } catch (\Exception $e) {
                echo "    ✗ Cannot fetch by exact ID: " . $e->getMessage() . "\n";
            }
        }
    } else {
        echo "  ✗ No results found\n";
    }
}
