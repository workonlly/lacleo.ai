<?php

namespace Tests\Unit;

use App\Elasticsearch\ElasticQueryBuilder;
use App\Filters\Handlers\ElasticsearchFilterHandler;
use App\Models\Company;
use App\Models\Filter;
use PHPUnit\Framework\TestCase;

class ElasticsearchFilterHandlerTest extends TestCase
{
    public function testKeywordFilterUsesTermsQuery()
    {
        $filter = new Filter([
            'filter_id' => 'business_category',
            'name' => 'Business Category',
            'group' => 'Company',
            'type' => 'keyword',
            'settings' => [
                'fields' => [
                    'company' => ['business_category'],
                ],
                'target_model' => Company::class,
            ],
        ]);

        $handler = new ElasticsearchFilterHandler($filter);
        $builder = new ElasticQueryBuilder(Company::class);

        $handler->apply($builder, ['include' => ['Software']]);
        $query = $builder->toArray();

        $this->assertArrayHasKey('query', $query);
        $bool = $query['query']['bool'] ?? [];
        $this->assertNotEmpty($bool);
        $filters = $bool['filter'] ?? [];
        $this->assertNotEmpty($filters);
        $foundTerms = false;
        foreach ($filters as $clause) {
            if (isset($clause['terms']) && isset($clause['terms']['business_category'])) {
                $foundTerms = true;
                break;
            }
        }
        $this->assertTrue($foundTerms, 'Expected terms clause for keyword filter');
    }

    public function testTextFilterUsesMatchPhrase()
    {
        $filter = new Filter([
            'filter_id' => 'company_domain',
            'name' => 'Company Domain',
            'group' => 'Company',
            'type' => 'text',
            'settings' => [
                'fields' => [
                    'company' => ['website'],
                ],
                'target_model' => Company::class,
            ],
        ]);

        $handler = new ElasticsearchFilterHandler($filter);
        $builder = new ElasticQueryBuilder(Company::class);

        $handler->apply($builder, ['include' => ['example.com']]);
        $query = $builder->toArray();

        $this->assertArrayHasKey('query', $query);
        $bool = $query['query']['bool'] ?? [];
        $this->assertNotEmpty($bool);
        $filters = $bool['filter'] ?? [];
        $this->assertNotEmpty($filters);
        $foundMatch = false;
        foreach ($filters as $clause) {
            if (isset($clause['match_phrase']) && isset($clause['match_phrase']['website'])) {
                $foundMatch = true;
                break;
            }
        }
        $this->assertTrue($foundMatch, 'Expected match_phrase clause for text filter');
    }
}

