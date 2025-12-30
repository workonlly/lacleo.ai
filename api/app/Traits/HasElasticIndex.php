<?php

namespace App\Traits;

use App\Elasticsearch\ElasticClient;
use App\Elasticsearch\ElasticQueryBuilder;
use Exception;
use Illuminate\Support\Facades\Log;

trait HasElasticIndex
{
    protected static ?ElasticClient $elasticClient = null;

    protected static function bootElasticModelTrait(): void
    {
        if (static::$elasticClient === null) {
            static::$elasticClient = app(ElasticClient::class);
        }
    }

    /* -----------------------------------------------------------------
     |  STRICT INDEX CONTRACT
     |------------------------------------------------------------------*/

    /**
     * Every model MUST explicitly declare its Elasticsearch index
     * via elasticIndex() or $elasticIndex property.
     * No fallbacks. No guessing.
     */
    public function getIndexName(): string
    {
        if (method_exists($this, 'elasticIndex')) {
            return $this->elasticIndex();
        }

        if (property_exists($this, 'elasticIndex')) {
            return $this->elasticIndex;
        }

        throw new Exception(
            sprintf(
                'Elastic index not defined for model [%s]. Declare elasticIndex() or $elasticIndex explicitly.',
                static::class
            )
        );
    }

    /**
     * Read alias == write alias == fixed index
     */
    public function getReadAlias(): string
    {
        return $this->getIndexName();
    }

    public function getWriteAlias(): string
    {
        return $this->getIndexName();
    }

    /* -----------------------------------------------------------------
     |  MAPPING & SETTINGS (READ-ONLY)
     |------------------------------------------------------------------*/

    public function getDynamicMapSetting(): bool|string
    {
        return method_exists($this, 'dynamicMapSetting')
            ? $this->dynamicMapSetting()
            : ($this->dynamicMapSetting ?? true);
    }

    public function getDynamicTemplates(): array
    {
        return method_exists($this, 'dynamicTemplates')
            ? $this->dynamicTemplates()
            : ($this->dynamicTemplates ?? []);
    }

    protected function getIndexSettings(): array
    {
        if (method_exists($this, 'elasticSettings')) {
            return $this->elasticSettings();
        }

        if (property_exists($this, 'elasticSettings')) {
            return $this->elasticSettings;
        }

        return [
            'number_of_shards' => 1,
            'number_of_replicas' => 1,
        ];
    }

    public function getMappingProperties(): array
    {
        if (method_exists($this, 'elasticMapping')) {
            return $this->elasticMapping();
        }

        if (property_exists($this, 'elasticMapping')) {
            return $this->elasticMapping;
        }

        throw new Exception(
            sprintf(
                'Elastic mapping not defined for model [%s]. Define elasticMapping().',
                static::class
            )
        );
    }

    public function getAdditionalMappingProperties(): array
    {
        return method_exists($this, 'additionalElasticMappingAttributes')
            ? $this->additionalElasticMappingAttributes()
            : ($this->additionalElasticMappingAttributes ?? []);
    }

    /* -----------------------------------------------------------------
     |  🚫 INDEX CREATION DISABLED
     |------------------------------------------------------------------*/

    /**
     * Index creation is FORBIDDEN at runtime.
     * Must be handled by migrations or infra scripts only.
     */
    public static function createIndex(): never
    {
        throw new Exception(
            'Runtime Elasticsearch index creation is disabled. Use predefined indices only.'
        );
    }

    public static function updateIndexMapping(): never
    {
        throw new Exception(
            'Runtime Elasticsearch mapping updates are disabled.'
        );
    }

    /* -----------------------------------------------------------------
     |  DOCUMENT OPERATIONS (SAFE)
     |------------------------------------------------------------------*/

    public function saveToElastic(array $options = []): array
    {
        static::bootElasticModelTrait();

        $index = $this->getIndexName();

        // Validate index exists before writing
        $exists = static::$elasticClient
            ->getClient()
            ->indices()
            ->exists(['index' => $index])
            ->asBool();

        if (!$exists) {
            throw new Exception(
                "Elasticsearch index [$index] does not exist. Aborting write."
            );
        }

        return static::$elasticClient->indexDocument(
            $index,
            $this->toElasticArray(),
            $this->getKey(),
            $options
        );
    }

    public static function findInElastic($id, array $options = [])
    {
        static::bootElasticModelTrait();
        $instance = new static;

        return static::$elasticClient->getDocument(
            $instance->getIndexName(),
            $id,
            $options
        );
    }

    public static function searchInElastic(array $query, array $options = [])
    {
        static::bootElasticModelTrait();
        $instance = new static;

        try {
            return static::$elasticClient->search([
                'index' => $instance->getIndexName(),
                'body'  => $query,
            ]);
        } catch (\Throwable $e) {
            Log::error('Elasticsearch search failed', [
                'index' => $instance->getIndexName(),
                'error' => $e->getMessage(),
            ]);

            return [
                'hits' => [
                    'total' => ['value' => 0, 'relation' => 'eq'],
                    'hits' => [],
                ],
                'aggregations' => [],
                'took' => 0,
            ];
        }
    }

    public function existsInElastic(): bool
    {
        static::bootElasticModelTrait();

        try {
            return static::$elasticClient
                ->getClient()
                ->exists([
                    'index' => $this->getIndexName(),
                    'id'    => $this->getKey(),
                ])
                ->asBool();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function refreshIndex(): array
    {
        static::bootElasticModelTrait();
        $instance = new static;

        return static::$elasticClient
            ->getClient()
            ->indices()
            ->refresh(['index' => $instance->getIndexName()])
            ->asArray();
    }

    /* -----------------------------------------------------------------
     |  QUERY BUILDER
     |------------------------------------------------------------------*/

    public static function query(): ElasticQueryBuilder
    {
        return new ElasticQueryBuilder(static::class);
    }

    public static function elastic(): ElasticQueryBuilder
    {
        return static::query();
    }

    /* -----------------------------------------------------------------
     |  TRANSFORMATION
     |------------------------------------------------------------------*/

    protected function toElasticArray(): array
    {
        $data = array_intersect_key(
            $this->toArray(),
            array_flip($this->getFillable())
        );

        if (method_exists($this, 'computedElasticAttributes')) {
            $data = array_merge($data, $this->computedElasticAttributes());
        }

        if (method_exists($this, 'transformElasticAttributes')) {
            $data = $this->transformElasticAttributes($data);
        }

        if (method_exists($this, 'additionalElasticAttributes')) {
            $data = array_merge($data, $this->additionalElasticAttributes());
        }

        return $data;
    }
}
