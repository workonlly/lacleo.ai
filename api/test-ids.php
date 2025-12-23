$ids = [
    "integramicro.com__Integra Micro Systems Pvt Ltd__",
    "flex1848.be__FLEX 1848__",
    "suncomfort.be__Suncomfort__",
    "hellenicsealines.gr__Hellenic Sea Lines S.C.__"
];

echo "=== Testing exact ID lookup ===\n\n";
foreach ($ids as $id) {
    echo "Trying to fetch ID: '$id'\n";
    echo "ID length: " . strlen($id) . "\n";
    try {
        $company = \App\Models\Company::findInElastic($id);
        if ($company) {
            echo "✓ FOUND!\n";
            echo "  Name: " . ($company->name ?? 'N/A') . "\n";
        } else {
            echo "✗ Not found (returned null)\n";
        }
    } catch (\Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "\n\n=== Searching by domain only ===\n\n";
$domains = ['integramicro.com', 'flex1848.be', 'suncomfort.be', 'hellenicsealines.gr'];
foreach ($domains as $domain) {
    echo "Searching for domain: '$domain'\n";
    try {
        $builder = \App\Models\Company::elastic()->filter(['term' => ['website' => $domain]]);
        $results = $builder->paginate(1, 10);
        $companies = $results['data'] ?? [];
        
        if (count($companies) > 0) {
            foreach ($companies as $company) {
                echo "  Found: " . ($company['_id'] ?? 'unknown ID') . "\n";
                echo "    Name: " . ($company['_source']['company'] ?? 'N/A') . "\n";
            }
        } else {
            echo "  ✗ No results\n";
        }
    } catch (\Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
