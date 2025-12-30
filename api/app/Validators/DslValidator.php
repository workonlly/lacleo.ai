<?php

namespace App\Validators;

use Illuminate\Support\Facades\Log;
use App\Services\FilterRegistry;

/**
 * Validates filter DSL structure and enforces filter placement rules.
 * 
 * This is the guardrail that prevents invalid DSL from reaching Elasticsearch.
 * All DSL must pass through this validator before execution.
 */
class DslValidator
{
    /**
     * Contact-only filter keys
     */
    private const CONTACT_ONLY_FILTERS = [
        'job_title',
        'title',
        'departments',
        'department',
        'seniority',
        'experience_years',
        'years_of_experience',
        'years_experience',
    ];

    /**
     * Company-only filter keys
     */
    private const COMPANY_ONLY_FILTERS = [
        'company_names',
        'company',
        'company_name',
        'industries',
        'industry',
        'technologies',
        'technology',
        'employee_count',
        'company_size',
        'company_headcount',
        'annual_revenue',
        'revenue',
        'company_keywords',
        'keywords',
    ];

    /**
     * Location fields that can be in either bucket with explicit type
     */
    private const LOCATION_FILTERS = [
        'location',
        'locations',
        'country',
        'countries',
        'state',
        'states',
        'city',
        'cities',
    ];

    /**
     * Validate DSL structure and filter placement
     * 
     * @param array $dsl Expected shape: ['contact' => [...], 'company' => [...]]
     * @return array ['valid' => bool, 'errors' => string[], 'normalized' => array]
     */
    public static function validate(array $dsl): array
    {
        $errors = [];
        $normalized = [
            'contact' => [],
            'company' => [],
        ];

        // Ensure both buckets exist
        if (!isset($dsl['contact'])) {
            $dsl['contact'] = [];
        }
        if (!isset($dsl['company'])) {
            $dsl['company'] = [];
        }

        $registry = collect(FilterRegistry::getFilters())->keyBy('id');

        // Validate contact bucket
        if (!is_array($dsl['contact'])) {
            $errors[] = 'Contact filters must be an array';
        } else {
            foreach ($dsl['contact'] as $key => $value) {
                $normalizedKey = self::normalizeFilterKey($key);
                $reg = $registry->get($normalizedKey);
                if (!$reg) {
                    $errors[] = "Unknown filter '{$key}'";
                    continue;
                }
                $applies = (array) ($reg['applies_to'] ?? []);
                
                // Check if this is a company-only filter in contact bucket
                if (in_array($normalizedKey, self::COMPANY_ONLY_FILTERS) || (!in_array('contact', $applies) && in_array('company', $applies))) {
                    $errors[] = "Filter '{$key}' belongs in company bucket, not contact bucket";
                    // Move it to company bucket
                    if (!isset($normalized['company'][$normalizedKey])) {
                        $normalized['company'][$normalizedKey] = $value;
                    }
                    continue;
                }
                
                $normalized['contact'][$normalizedKey] = $value;
            }
        }

        // Validate company bucket
        if (!is_array($dsl['company'])) {
            $errors[] = 'Company filters must be an array';
        } else {
            foreach ($dsl['company'] as $key => $value) {
                $normalizedKey = self::normalizeFilterKey($key);
                $reg = $registry->get($normalizedKey);
                if (!$reg) {
                    $errors[] = "Unknown filter '{$key}'";
                    continue;
                }
                $applies = (array) ($reg['applies_to'] ?? []);
                
                // CRITICAL: Job titles must NEVER be in company bucket
                if (in_array($normalizedKey, self::CONTACT_ONLY_FILTERS) || (!in_array('company', $applies) && in_array('contact', $applies))) {
                    $errors[] = "Filter '{$key}' belongs in contact bucket, not company bucket (CRITICAL: job titles must be contact-level)";
                    // Move it to contact bucket
                    if (!isset($normalized['contact'][$normalizedKey])) {
                        $normalized['contact'][$normalizedKey] = $value;
                    }
                    continue;
                }
                
                $normalized['company'][$normalizedKey] = $value;
            }
        }

        // Validate structure of each filter value
        foreach (['contact', 'company'] as $bucket) {
            foreach ($normalized[$bucket] as $key => $value) {
                $structureErrors = self::validateFilterStructure($key, $value, $bucket);
                $reg = $registry->get($key);
                if ($reg) {
                    $supportsExclusion = (bool) ($reg['filtering']['supports_exclusion'] ?? false);
                    if (!$supportsExclusion && is_array($normalized[$bucket][$key]) && !empty($normalized[$bucket][$key]['exclude'] ?? [])) {
                        $normalized[$bucket][$key]['exclude'] = [];
                        $errors[] = "Exclusion not supported for {$bucket}.{$key}";
                    }
                    $mode = (string) ($reg['filtering']['mode'] ?? 'term');
                    if ($mode === 'range') {
                        $hasRange = is_array($normalized[$bucket][$key]) && isset($normalized[$bucket][$key]['range']);
                        if (!$hasRange) {
                            $errors[] = "Range required for {$bucket}.{$key}";
                        }
                    }
                    if ($mode === 'exists') {
                        if (!is_array($normalized[$bucket][$key])) {
                            $normalized[$bucket][$key] = [];
                        }
                        $normalized[$bucket][$key]['presence'] = $normalized[$bucket][$key]['presence'] ?? 'known';
                        $normalized[$bucket][$key]['include'] = [];
                        $normalized[$bucket][$key]['exclude'] = [];
                    }
                }
                $errors = array_merge($errors, $structureErrors);
            }
        }

        $valid = empty($errors);

        // Log validation results
        if (!$valid) {
            Log::warning('DSL Validation Failed', [
                'errors' => $errors,
                'input_dsl' => $dsl,
                'normalized_dsl' => $normalized,
            ]);
        } else {
            Log::debug('DSL Validation Passed', [
                'normalized_dsl' => $normalized,
            ]);
        }

        return [
            'valid' => $valid,
            'errors' => $errors,
            'normalized' => $normalized,
        ];
    }

    /**
     * Normalize filter key to standard form
     */
    private static function normalizeFilterKey(string $key): string
    {
        $key = strtolower(trim($key));
        
        // Normalize common variations
        $map = [
            'title' => 'job_title',
            'department' => 'departments',
            'years_of_experience' => 'experience_years',
            'years_experience' => 'experience_years',
            // Company name canonical id in registry is 'company_name'
            'company_names' => 'company_name',
            'company' => 'company_name',
            'company_name' => 'company_name',
            // Industry canonical id is 'industry'
            'industries' => 'industry',
            'industry' => 'industry',
            // Technologies canonical id is 'technologies'
            'technology' => 'technologies',
            // Employee count canonical id is 'employee_count'
            'company_size' => 'employee_count',
            'company_headcount' => 'employee_count',
            // Revenue canonical id is 'annual_revenue'
            'revenue' => 'annual_revenue',
            // Company keywords canonical id is 'keywords'
            'company_keywords' => 'keywords',
            'keywords' => 'keywords',
            // Location generic keys are normalized; bucket-specific resolution happens later
            'country' => 'countries',
            'state' => 'states',
            'city' => 'cities',
        ];

        return $map[$key] ?? $key;
    }

    /**
     * Validate the structure of a filter value
     */
    private static function validateFilterStructure(string $key, $value, string $bucket): array
    {
        $errors = [];

        if (!is_array($value)) {
            // Allow simple scalar values for backward compatibility
            return [];
        }

        // Expected structure: { include?: [], exclude?: [], range?: {min, max}, presence?: string }
        $allowedKeys = ['include', 'exclude', 'range', 'presence', 'operator'];
        
        foreach (array_keys($value) as $k) {
            if (!in_array($k, $allowedKeys)) {
                $errors[] = "Unknown key '{$k}' in {$bucket}.{$key} filter";
            }
        }

        // Validate include/exclude arrays
        if (isset($value['include']) && !is_array($value['include'])) {
            $errors[] = "{$bucket}.{$key}.include must be an array";
        }
        if (isset($value['exclude']) && !is_array($value['exclude'])) {
            $errors[] = "{$bucket}.{$key}.exclude must be an array";
        }

        // Validate range structure
        if (isset($value['range'])) {
            if (!is_array($value['range'])) {
                $errors[] = "{$bucket}.{$key}.range must be an object with min/max";
            } else {
                $rangeKeys = array_keys($value['range']);
                $validRangeKeys = ['min', 'max', 'gte', 'lte'];
                foreach ($rangeKeys as $rk) {
                    if (!in_array($rk, $validRangeKeys)) {
                        $errors[] = "Invalid range key '{$rk}' in {$bucket}.{$key}.range";
                    }
                }
            }
        }

        // Validate presence
        if (isset($value['presence']) && !in_array($value['presence'], ['known', 'unknown'])) {
            $errors[] = "{$bucket}.{$key}.presence must be 'known' or 'unknown'";
        }

        return $errors;
    }

    /**
     * Check if a filter key is contact-only
     */
    public static function isContactOnly(string $key): bool
    {
        $normalized = self::normalizeFilterKey($key);
        return in_array($normalized, self::CONTACT_ONLY_FILTERS);
    }

    /**
     * Check if a filter key is company-only
     */
    public static function isCompanyOnly(string $key): bool
    {
        $normalized = self::normalizeFilterKey($key);
        return in_array($normalized, self::COMPANY_ONLY_FILTERS);
    }

    /**
     * Detect entity type from DSL
     * 
     * @param array $dsl
     * @return string 'contacts' or 'companies'
     */
    public static function detectEntity(array $dsl): string
    {
        $contactFilters = $dsl['contact'] ?? [];
        $companyFilters = $dsl['company'] ?? [];

        // If job_title exists, always return contacts
        foreach ($contactFilters as $key => $value) {
            $normalized = self::normalizeFilterKey($key);
            if ($normalized === 'job_title') {
                return 'contacts';
            }
        }

        // If any contact-only filter exists, return contacts
        foreach ($contactFilters as $key => $value) {
            if (self::isContactOnly($key)) {
                return 'contacts';
            }
        }

        // Prefer companies when company filters are present unless explicit contact-only terms exist
        if (!empty($companyFilters)) {
            return 'companies';
        }

        // Default
        return 'contacts';
    }
}
