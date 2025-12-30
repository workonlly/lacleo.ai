<?php

namespace Tests\Unit;

use App\Services\FilterRegistry;
use PHPUnit\Framework\TestCase;

class FilterRegistryTest extends TestCase
{
    public function testCompanyDomainSuggestFieldsPresent()
    {
        $filters = FilterRegistry::getFilters();
        $byId = [];
        foreach ($filters as $f) {
            $byId[$f['id']] = $f;
        }
        $domain = $byId['company_domain'] ?? [];
        $search = $domain['search'] ?? [];
        $suggest = $search['suggest_fields'] ?? [];
        $this->assertContains('website', $suggest);
        $this->assertContains('domain', $suggest);
    }

    public function testIndustrySuggestFieldsIncludeBusinessCategory()
    {
        $filters = FilterRegistry::getFilters();
        $byId = [];
        foreach ($filters as $f) {
            $byId[$f['id']] = $f;
        }
        $industry = $byId['business_category'] ?? [];
        $search = $industry['search'] ?? [];
        $suggest = $search['suggest_fields'] ?? [];
        $this->assertContains('industry', $suggest);
        $this->assertContains('business_category', $suggest);
    }
}

