<?php

namespace App\Elasticsearch;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Exception;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ElasticClient
{
    protected Client $client;
    protected array $config;

    protected array $defaultHeaders = [
        'Accept' => 'application/vnd.elasticsearch+json; compatible-with=8',
        'Content-Type' => 'application/vnd.elasticsearch+json; compatible-with=8',
    ];

    /**
     * Bootstrap Elasticsearch client
     */
    public function __construct()
    {
        $this->config = config('elasticsearch');

        $this->validateConfig();
        $this->initializeClient();
        $this->validateRequiredIndices();
    }

    /* -----------------------------------------------------------------
     |  CONFIG VALIDATION
     |------------------------------------------------------------------*/

    protected function validateConfig(): void
    {
        if (empty($this->config['hosts']) || ! is_array($this->config['hosts'])) {
            throw new InvalidArgumentException(
                'Elasticsearch hosts configuration is missing or invalid'
            );
        }
    }

    /* -----------------------------------------------------------------
     |  CLIENT INITIALIZATION
     |------------------------------------------------------------------*/

    protected function initializeClient(): void
    {
        $builder = ClientBuilder::create()
            ->setHosts($this->config['hosts']);

        // SSL
        if (($this->config['ssl']['verify'] ?? false) === true) {
            $builder->setSSLVerification(
                $this->config['ssl']['cafile'] ?? null
            );
        } else {
            $builder->setSSLVerification(false);
        }

        // Authentication
        if (! empty($this->config['auth']['api_key'])
            && ! empty($this->config['auth']['api_key_secret'])
        ) {
            $builder->setApiKey(
                base64_encode(
                    $this->config['auth']['api_key']
                    . ':'
                    . $this->config['auth']['api_key_secret']
                )
            );
        } elseif (! empty($this->config['auth']['api_key'])) {
            $builder->setApiKey($this->config['auth']['api_key']);
        } elseif (! empty($this->config['auth']['username'])) {
            $builder->setBasicAuthentication(
                $this->config['auth']['username'],
                $this->config['auth']['password'] ?? ''
            );
        }

        if (! empty($this->config['retries'])) {
            $builder->setRetries((int) $this->config['retries']);
        }

        $this->client = $builder->build();
    }

    /* -----------------------------------------------------------------
     |  INDEX CONTRACT VALIDATION (FAIL FAST)
     |------------------------------------------------------------------*/

    protected function validateRequiredIndices(): void
    {
        try {
            $indices = IndexResolver::all(); // MUST return explicit index names

            foreach ($indices as $index) {
                $exists = $this->client
                    ->indices()
                    ->exists($this->withHeaders(['index' => $index]))
                    ->asBool();

                if (! $exists) {
                    Log::channel('elastic')->critical(
                        'Required Elasticsearch index missing',
                        ['index' => $index]
                    );

                    throw new InvalidArgumentException(
                        "Required Elasticsearch index does not exist: {$index}"
                    );
                }
            }
        } catch (Exception $e) {
            Log::channel('elastic')->critical(
                'Elasticsearch startup validation failed',
                ['error' => $e->getMessage()]
            );

            throw $e;
        }
    }

    /* -----------------------------------------------------------------
     |  🚫 HARD BLOCK: ALIAS / INDEX CREATION
     |------------------------------------------------------------------*/

    public function ensureAlias(string $alias, array $settings = [], array $mapping = []): never
    {
        throw new InvalidArgumentException(
            'Alias or index auto-creation is disabled by contract'
        );
    }

    /* -----------------------------------------------------------------
     |  DOCUMENT OPERATIONS
     |------------------------------------------------------------------*/

    public function indexDocument(
        string $index,
        array|string $body,
        ?string $id = null,
        array $options = []
    ): array {
        $this->assertIndexExists($index);

        try {
            $params = [
                'index' => $index,
                'body' => $body,
                'refresh' => $options['refresh'] ?? true,
            ];

            if ($id !== null) {
                $params['id'] = $id;
            }

            $response = $this->client->index(
                $this->withHeaders($params)
            )->asArray();

            return $response;

        } catch (ClientResponseException | ServerResponseException $e) {
            Log::channel('elastic')->error('Indexing failed', [
                'index' => $index,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getDocument(
        string $index,
        string $id,
        array $options = []
    ): array {
        $this->assertIndexExists($index);

        return $this->client->get(
            $this->withHeaders([
                'index' => $index,
                'id' => $id,
            ])
        )->asArray();
    }

    public function search(array $params): array
    {
        $index = $params['index'] ?? null;

        if (! is_string($index)) {
            throw new InvalidArgumentException('Search requires explicit index');
        }

        $this->assertIndexExists($index);

        try {
            return $this->client
                ->search($this->withHeaders($params))
                ->asArray();
        } catch (ClientResponseException | ServerResponseException $e) {
            Log::channel('elastic')->error('Search failed', [
                'index' => $index,
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

    /* -----------------------------------------------------------------
     |  MAPPING (READ-ONLY)
     |------------------------------------------------------------------*/

    public function putMapping(string $index, array $mapping): never
    {
        throw new InvalidArgumentException(
            'Runtime mapping updates are disabled. Use offline migrations.'
        );
    }

    /* -----------------------------------------------------------------
     |  HEALTH
     |------------------------------------------------------------------*/

    public function ping(): bool
    {
        try {
            $this->client->info($this->withHeaders([]))->asArray();
            return true;
        } catch (Exception $e) {
            Log::channel('elastic')->error(
                'Elasticsearch cluster unreachable',
                ['error' => $e->getMessage()]
            );
            return false;
        }
    }

    /* -----------------------------------------------------------------
     |  INTERNAL SAFETY
     |------------------------------------------------------------------*/

    protected function assertIndexExists(string $index): void
    {
        $exists = $this->client
            ->indices()
            ->exists($this->withHeaders(['index' => $index]))
            ->asBool();

        if (! $exists) {
            throw new InvalidArgumentException(
                "Elasticsearch index does not exist: {$index}"
            );
        }
    }

    protected function withHeaders(array $params): array
    {
        $client = $params['client'] ?? [];
        $client['headers'] = array_merge(
            $client['headers'] ?? [],
            $this->defaultHeaders
        );

        $params['client'] = $client;
        return $params;
    }

    /* -----------------------------------------------------------------
     |  RAW CLIENT
     |------------------------------------------------------------------*/

    public function getClient(): Client
    {
        return $this->client;
    }
}
