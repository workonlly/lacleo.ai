<?php

namespace App\Models;

use App\Traits\HasElasticIndex;
use App\Services\RecordNormalizer;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasElasticIndex;

    protected $dynamicMapSetting = 'false';

    protected $fillable = [
        'id',
        'website',
        'company',
        'companyLinkedinUrl',
        'companyEmail',
        'companyPhone',
        'employees',
        'keywords',
        'businessCategory',
        'serviceProduct',
        'socialMedia',
        'companyAddress',
        'location',
        'seoDescription',
        'technologies',
        'funding',
        'annualRevenue',
        'retailLocations',
        'naicsCodes',
        'sicCodes',
        'businessDescription',
        'foundedYear',
    ];

    protected $casts = [
        'employees' => 'integer',
        'socialMedia' => 'array',
        'location' => 'array',
        'funding' => 'array',
        'annualRevenue' => 'float',
        'retailLocations' => 'integer',
        'naicsCodes' => 'array',
        'sicCodes' => 'array',
        'foundedYear' => 'integer',
        'keywords' => 'array',
        'technologies' => 'array',
    ];

    protected $dynamicTemplates = [
        [
            'all_text_fields' => [
                'match_mapping_type' => 'string',
                'mapping' => [
                    'type' => 'text',
                    'analyzer' => 'iq_text_base',
                    'fields' => [
                        'delimiter' => [
                            'analyzer' => 'iq_text_delimiter',
                            'type' => 'text',
                            'index_options' => 'freqs',
                        ],
                        'joined' => [
                            'search_analyzer' => 'q_text_bigram',
                            'analyzer' => 'i_text_bigram',
                            'type' => 'text',
                            'index_options' => 'freqs',
                        ],
                        'prefix' => [
                            'search_analyzer' => 'q_prefix',
                            'analyzer' => 'i_prefix',
                            'type' => 'text',
                            'index_options' => 'docs',
                        ],
                        'enum' => [
                            'ignore_above' => 2048,
                            'type' => 'keyword',
                        ],
                        'stem' => [
                            'analyzer' => 'iq_text_stem',
                            'type' => 'text',
                        ],
                        'sort' => [
                            'type' => 'keyword',
                            'normalizer' => 'lowercase',
                            'doc_values' => true,
                        ],
                    ],
                ],
            ],
        ],
        [
            'strings_as_keywords' => [
                'match_mapping_type' => 'string',
                'mapping' => [
                    'type' => 'keyword',
                    'ignore_above' => 256,
                    'fields' => [
                        'sort' => [
                            'type' => 'keyword',
                            'normalizer' => 'lowercase',
                            'doc_values' => true,
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function elasticIndex(): string
    {
        return \App\Elasticsearch\IndexResolver::companies();
    }

    public function elasticReadAlias(): string
    {
        return $this->elasticIndex();
    }

    /**
     * Define custom index settings
     */
    public function elasticSettings(): array
    {
        return [
            'number_of_shards' => 1,
            'number_of_replicas' => 1,
            'analysis' => [
                'filter' => [
                    'front_ngram' => [
                        'type' => 'edge_ngram',
                        'min_gram' => '1',
                        'max_gram' => '12',
                    ],
                    'bigram_joiner' => [
                        'max_shingle_size' => '2',
                        'token_separator' => '',
                        'output_unigrams' => 'false',
                        'type' => 'shingle',
                    ],
                    'bigram_max_size' => [
                        'type' => 'length',
                        'max' => '16',
                        'min' => '0',
                    ],
                    'en-stem-filter' => [
                        'name' => 'light_english',
                        'type' => 'stemmer',
                        'language' => 'light_english',
                    ],
                    'bigram_joiner_unigrams' => [
                        'max_shingle_size' => '2',
                        'token_separator' => '',
                        'output_unigrams' => 'true',
                        'type' => 'shingle',
                    ],
                    'delimiter' => [
                        'split_on_numerics' => 'true',
                        'generate_word_parts' => 'true',
                        'preserve_original' => 'false',
                        'catenate_words' => 'true',
                        'generate_number_parts' => 'true',
                        'catenate_all' => 'true',
                        'split_on_case_change' => 'true',
                        'type' => 'word_delimiter_graph',
                        'catenate_numbers' => 'true',
                        'stem_english_possessive' => 'true',
                    ],
                    'en-stop-words-filter' => [
                        'type' => 'stop',
                        'stopwords' => '_english_',
                    ],
                ],
                'analyzer' => [
                    'i_prefix' => [
                        'filter' => [
                            'cjk_width',
                            'lowercase',
                            'asciifolding',
                            'front_ngram',
                        ],
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                    ],
                    'iq_text_delimiter' => [
                        'filter' => [
                            'delimiter',
                            'cjk_width',
                            'lowercase',
                            'asciifolding',
                            'en-stop-words-filter',
                            'en-stem-filter',
                        ],
                        'type' => 'custom',
                        'tokenizer' => 'whitespace',
                    ],
                    'q_prefix' => [
                        'filter' => [
                            'cjk_width',
                            'lowercase',
                            'asciifolding',
                        ],
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                    ],
                    'iq_text_base' => [
                        'filter' => [
                            'cjk_width',
                            'lowercase',
                            'asciifolding',
                            'en-stop-words-filter',
                        ],
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                    ],
                    'iq_text_stem' => [
                        'filter' => [
                            'cjk_width',
                            'lowercase',
                            'asciifolding',
                            'en-stop-words-filter',
                            'en-stem-filter',
                        ],
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                    ],
                    'i_text_bigram' => [
                        'filter' => [
                            'cjk_width',
                            'lowercase',
                            'asciifolding',
                            'en-stem-filter',
                            'bigram_joiner',
                            'bigram_max_size',
                        ],
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                    ],
                    'q_text_bigram' => [
                        'filter' => [
                            'cjk_width',
                            'lowercase',
                            'asciifolding',
                            'en-stem-filter',
                            'bigram_joiner_unigrams',
                            'bigram_max_size',
                        ],
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                    ],
                ],
            ],
        ];
    }

    /**
     * Define custom elastic mapping attributes
     */
    public function additionalElasticMappingAttributes(): array
    {
        return [
            'embedding' => [
                'type' => 'dense_vector',
                'dims' => 768, // Ollama nomic-embed-text
                'index' => true,
                'similarity' => 'dot_product',
            ],
            'technologies_normalized' => [
                'type' => 'keyword',
            ],
        ];
    }

    public function additionalElasticAttributes(): array
    {
        $techs = (array) ($this->technologies ?? []);

        return [
            'technologies_normalized' => RecordNormalizer::normalizeTechnologies($techs),
        ];
    }

    public static function globalSearchFields()
    {
        return [
            'exact_fields' => [
                'company.keyword' => 10,
                'company_also_known_as.keyword' => 8,
                'website' => 7,
                'company_linkedin_url' => 7,
                'business_category' => 6,
                'keywords' => 5,
            ],
            'phrase_fields' => [
                'company' => 6,
                'company_also_known_as' => 5,
                'business_description' => 3,
                'service_or_product' => 4,
            ],
            'prefix_fields' => [
                'company' => 4,
                'company_also_known_as' => 3,
            ],
            'ngram_fields' => [
                'company.joined' => 3,
                'company_also_known_as.joined' => 2,
            ],
            'text_fields' => [
                'company' => 3,
                'company_also_known_as' => 2,
                'business_description' => 1,
                'service_or_product' => 1,
                'seo_description' => 1,
                'location.street' => 1,
                'location.city' => 1,
                'location.state' => 1,
                'location.country' => 1,
            ],
        ];
    }
}
