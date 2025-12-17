<?php
namespace App\Models;
use App\Traits\HasElasticIndex;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class Contact extends Model
{
    use HasElasticIndex, HasUuids;
    protected $fillable = [
        'id',
        'first_name',
        'last_name',
        'full_name',
        'title',
        'emails',
        'linkedin_url',
        'seniority',
        'departments',
        'location',
        'socialMedia',
        'phone_numbers',
    ];
    protected $casts = [
        'location' => 'array',
        'socialMedia' => 'array',
        'departments' => 'array',
        'emails' => 'array',
        'phone_numbers' => 'array',
    ];
    protected $dynamicMapSetting = 'false';

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
        $envIndex = env('ELASTICSEARCH_CONTACT_INDEX');
        if (!empty($envIndex)) {
            return $envIndex;
        }
        $prefix = config('elasticsearch.index_prefix', '');

        return trim($prefix . '_' . strtolower(class_basename($this)), '_');
    }

    public function elasticReadAlias(): string
    {
        return $this->elasticIndex();
    }

    public function elasticSettings(): array
    {
        return [
            'number_of_shards' => 1,
            'number_of_replicas' => 1,
            'analysis' => [
                'normalizer' => [
                    'lowercase' => [
                        'type' => 'custom',
                        'char_filter' => [],
                        'filter' => ['lowercase'],
                    ],
                ],
                'filter' => [
                    'title_synonyms' => [
                        'type' => 'synonym_graph',
                        'synonyms' => [
                            'cto, chief technology officer, chief technical officer, vp engineering, vp technology, head of engineering, technical director, tech lead',
                            'ceo, chief executive officer, founder, co-founder, managing director, president',
                            'coo, chief operations officer, operations head',
                            'cfo, chief financial officer, finance head, vp finance',
                            'cio, chief information officer, it director, director information systems',
                            'manager, product manager, marketing manager, engineering manager, sales manager, operations manager, project manager, program manager',
                            'director, sr director, director of, head of',
                            'vp, vice president, vp of',
                            'tech, technology, engineering, software',
                            'cfos, chief financial officers',
                            'ciso, chief information security officer',
                            'cso, chief security officer',
                            'ceo, chief executive officer',
                            'cmo, chief marketing officer',
                        ],
                    ],
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
                    'title_synonym_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => [
                            'lowercase',
                            'asciifolding',
                            'title_synonyms',
                        ],
                    ],
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
        ];
    }

    public static function globalSearchFields()
    {
        return [
            'exact_fields' => [
                'full_name.keyword' => 10,
                'first_name.keyword' => 8,
                'last_name.keyword' => 8,
                'emails.email' => 7,
                'linkedin_url' => 6,
                'title.keyword' => 5,
                'departments' => 5,
                'seniority' => 5,
            ],
            'phrase_fields' => [
                'full_name' => 6,
                'title' => 4,
                'company' => 3,
            ],
            'prefix_fields' => [
                'full_name' => 4,
                'first_name' => 3,
                'last_name' => 3,
                'title' => 2,
            ],
            'ngram_fields' => [
                'full_name.joined' => 3,
                'first_name.joined' => 2,
                'last_name.joined' => 2,
                'title.joined' => 2,
            ],
            'text_fields' => [
                'full_name' => 3,
                'first_name' => 2,
                'last_name' => 2,
                'title' => 1,
                'company' => 1,
                'location.street' => 1,
                'location.city' => 1,
                'location.state' => 1,
                'location.country' => 1,
            ],
        ];
    }

    public function additionalElasticAttributes(): array
    {
        $title = (string) ($this->title ?? '');
        $normalized = trim(mb_strtolower($title));

        $seniority = null;
        $t = mb_strtolower($title);
        if ($t !== '') {
            if (preg_match('/\b(ceo|cto|cfo|coo|cso|ciso|president|founder|co-founder|chief)\b/i', $title)) {
                $seniority = 'Executive';
            } elseif (preg_match('/\b(vp|vice\s+president|svp|avp)\b/i', $title)) {
                $seniority = 'VP';
            } elseif (preg_match('/\bdirector\b/i', $t)) {
                $seniority = 'Director';
            } elseif (preg_match('/\bmanager\b/i', $t)) {
                $seniority = 'Manager';
            } elseif (preg_match('/\b(lead|head)\b/i', $t)) {
                $seniority = 'Lead';
            } elseif (preg_match('/\b(intern|junior|associate)\b/i', $t)) {
                $seniority = 'Entry';
            } elseif (preg_match('/\b(senior|sr\.?|staff)\b/i', $t)) {
                $seniority = 'Senior';
            } elseif (preg_match('/\b(mid|middle)\b/i', $t)) {
                $seniority = 'Mid';
            }
        }

        $department = null;
        if ($t !== '') {
            if (preg_match('/\b(marketing|growth|brand|performance)\b/i', $t)) {
                $department = 'Marketing';
            } elseif (preg_match('/\b(sales|revenue|account\s*exec|business\s*development)\b/i', $t)) {
                $department = 'Sales';
            } elseif (preg_match('/\b(product|pm|program\s*manager)\b/i', $t)) {
                $department = 'Product';
            } elseif (preg_match('/\b(engineering|tech|software|developer|devops|data\s*engineer)\b/i', $t)) {
                $department = 'Engineering';
            } elseif (preg_match('/\b(operations|ops|supply\s*chain|logistics)\b/i', $t)) {
                $department = 'Operations';
            } elseif (preg_match('/\b(finance|accounting)\b/i', $t)) {
                $department = 'Finance';
            } elseif (preg_match('/\b(hr|talent|people)\b/i', $t)) {
                $department = 'HR';
            } elseif (preg_match('/\b(it|information\s*technology|systems|cio|sysadmin)\b/i', $t)) {
                $department = 'IT';
            }
        }

        return [
            'job_title' => $title,
            'normalized_title' => $normalized,
            'title_keywords' => $title,
            'title_synonyms' => $title,
            'seniority_level' => $seniority,
            'department' => $department,
        ];
    }

    /**
     * Create model instance from API response
     */
    public static function createFromApiResponse($documentId, array $contact): ?self
    {
        if (empty($contact)) {
            return null;
        }

        $attributes = [
            'id' => $documentId,
            'first_name' => $contact['first_name'] ?? null,
            'last_name' => $contact['last_name'] ?? null,
            'full_name' => $contact['full_name'] ?? null,
            'title' => $contact['headline'] ?? null,
            'emails' => array_map(fn($email) => [
                'type' => strtolower($email['type']),
                'email' => $email['value'],
                'email_status' => $email['verification']['result'] ?? 'unknown',
            ], $contact['email_addresses'] ?? []),
            'phone_numbers' => array_map(fn($phone) => [
                'type' => $phone['type'],
                'phone_number' => $phone['value'],
                'is_valid' => $phone['verification']['is_valid'] ?? false,
            ], $contact['phone_numbers'] ?? []),
        ];

        // Extract social media URLs
        if (!empty($contact['external_urls'])) {
            $attributes['social_media'] = [];
            foreach ($contact['external_urls'] as $url) {
                $type = strtolower($url['type']);
                switch ($type) {
                    case 'linkedin':
                        $attributes['linkedin_url'] = $url['value'];
                        break;
                    case 'facebook':
                        $attributes['social_media']['facebook_url'] = $url['value'];
                        break;
                    case 'github':
                        $attributes['social_media']['github_url'] = $url['value'];
                        break;
                    case 'twitter':
                        $attributes['social_media']['twitter_url'] = $url['value'];
                        break;
                    case 'youtube':
                        $attributes['social_media']['youtube_url'] = $url['value'];
                        break;
                }
            }
        }

        // Add LinkedIn URL directly from contact if available and not already set
        if (!isset($attributes['linkedin_url']) && !empty($contact['linkedin_url'])) {
            $attributes['linkedin_url'] = $contact['linkedin_url'];
        }

        foreach ($contact['companies'] ?? [] as $company) {
            if (!empty($company['info']['is_current'])) {
                $attributes['company'] = $company['name'];
                $attributes['website'] = $company['domain'];
                break;
            }
        }

        $instance = new self($attributes);
        $instance->saveToElastic();

        return $instance;
    }
}
