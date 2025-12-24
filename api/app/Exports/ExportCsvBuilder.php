<?php

namespace App\Exports;

use App\Services\RecordNormalizer;
use Illuminate\Support\Facades\Log;

class ExportCsvBuilder
{
    /**
     * Decode company ID by converting + to spaces (from URL encoding)
     */
    private static function decodeCompanyId(string $id): string
    {
        // Replace + with space (form-encoded spaces) and then urldecode for other encoded chars
        return urldecode(str_replace('+', ' ', $id));
    }

    public const FULL_HEADER = [
        'domain',
        'first_name',
        'last_name',
        'title',
        'work_email',
        'personal_email',
        'seniority',
        'departments',
        'mobile_number',
        'direct_number',
        'person_linkedin_url',
        'city',
        'state',
        'country',
        'website',
        'company_name',
        'number_of_employees',
        'industry',
        'linkedin_url',
        'facebook_url',
        'twitter_url',
        'street',
        'company_city',
        'company_state',
        'company_country',
        'postal_code',
        'company_address',
        'keywords',
        'company_phone_number',
        'technologies',
        'total_funding_usd',
        'latest_funding',
        'latest_funding_amount',
        'last_raised_at',
        'annual_revenue_usd',
        'sic_code',
        'short_description',
        'founded_year',
    ];

    public const EMAIL_ONLY_HEADER = [
        'domain',
        'first_name',
        'last_name',
        'title',
        'work_email',
        'personal_email',
        'seniority',
        'departments',
        'person_linkedin_url',
        'city',
        'state',
        'country',
        'website',
        'company_name',
        'number_of_employees',
        'industry',
        'linkedin_url',
        'facebook_url',
        'twitter_url',
        'street',
        'company_city',
        'company_state',
        'company_country',
        'postal_code',
        'company_address',
        'keywords',
        'technologies',
        'total_funding_usd',
        'latest_funding',
        'latest_funding_amount',
        'last_raised_at',
        'annual_revenue_usd',
        'sic_code',
        'short_description',
        'founded_year',
    ];

    public const PHONE_ONLY_HEADER = [
        'domain',
        'first_name',
        'last_name',
        'title',
        'seniority',
        'departments',
        'mobile_number',
        'direct_number',
        'person_linkedin_url',
        'city',
        'state',
        'country',
        'website',
        'company_name',
        'number_of_employees',
        'industry',
        'linkedin_url',
        'facebook_url',
        'twitter_url',
        'street',
        'company_city',
        'company_state',
        'company_country',
        'postal_code',
        'company_address',
        'company_phone_number',
        'keywords',
        'technologies',
        'total_funding_usd',
        'latest_funding',
        'latest_funding_amount',
        'last_raised_at',
        'annual_revenue_usd',
        'sic_code',
        'short_description',
        'founded_year',
    ];

    public const FREE_EXPORT_HEADER = [
        'domain',
        'first_name',
        'last_name',
        'title',
        'seniority',
        'departments',
        'person_linkedin_url',
        'city',
        'state',
        'country',
        'website',
        'company_name',
        'number_of_employees',
        'industry',
        'linkedin_url',
        'facebook_url',
        'twitter_url',
        'street',
        'company_city',
        'company_state',
        'company_country',
        'postal_code',
        'company_address',
        'keywords',
        'technologies',
        'total_funding_usd',
        'latest_funding',
        'latest_funding_amount',
        'last_raised_at',
        'annual_revenue_usd',
        'sic_code',
        'short_description',
        'founded_year',
    ];
    public const CONTACT_HEADERS_PII = [
        'domain',
        'first_name',
        'last_name',
        'title',
        'work_email',
        'personal_email',
        'seniority',
        'departments',
        'mobile_number',
        'direct_number',
        'person_linkedin_url',
        'city',
        'state',
        'country',
    ];

    public const CONTACT_HEADERS_FREE = [
        'domain',
        'first_name',
        'last_name',
        'title',
        'seniority',
        'departments',
        'person_linkedin_url',
        'city',
        'state',
        'country',
    ];

    public const COMPANY_HEADERS_PII = [
        'domain',
        'first_name',
        'last_name',
        'title',
        'work_email',
        'personal_email',
        'seniority',
        'departments',
        'mobile_number',
        'direct_number',
        'person_linkedin_url',
        'city',
        'state',
        'country',
        'company_name',
        'number_of_employees',
        'industry',
        'linkedin_url',
        'facebook_url',
        'twitter_url',
        'street',
        'city',
        'state',
        'country',
        'postal_code',
        'address',
        'keywords',
        'phone_number',
        'technologies',
        'total_funding_usd',
        'latest_funding',
        'latest_funding_amount',
        'last_raised_at',
        'annual_revenue_usd',
        'sic_code',
        'short_description',
        'founded_year',
    ];

    public const COMPANY_HEADERS_FREE = [
        'domain',
        'first_name',
        'last_name',
        'title',
        'seniority',
        'departments',
        'person_linkedin_url',
        'city',
        'state',
        'country',
        'company_name',
        'number_of_employees',
        'industry',
        'linkedin_url',
        'facebook_url',
        'twitter_url',
        'street',
        'city',
        'state',
        'country',
        'postal_code',
        'address',
        'keywords',
        'technologies',
        'total_funding_usd',
        'latest_funding',
        'latest_funding_amount',
        'last_raised_at',
        'annual_revenue_usd',
        'sic_code',
        'short_description',
        'founded_year',
    ];

    public static function buildContactsCsv(array $contacts, bool $sanitize = false): string
    {
        $contactsNorm = array_map(fn($c) => RecordNormalizer::normalizeContact(is_array($c) ? $c : ($c ? $c->toArray() : [])), $contacts);
        
        // Define headers for contact data only
        $headers = [
            'first_name',
            'last_name',
            'title',
            'work_email',
            'personal_email',
            'seniority',
            'departments',
            'mobile_number',
            'direct_number',
            'person_linkedin_url',
            'city',
            'state',
            'country',
            'company',
            'website'
        ];
        
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $sanitize ? array_diff($headers, ['work_email', 'personal_email', 'mobile_number', 'direct_number']) : $headers);

        foreach ($contactsNorm as $contact) {
            $row = [
                (string) ($contact['first_name'] ?? ''),
                (string) ($contact['last_name'] ?? ''),
                (string) ($contact['title'] ?? ''),
                $sanitize ? '' : (string) ($contact['work_email'] ?? ''),
                $sanitize ? '' : (string) ($contact['personal_email'] ?? ''),
                (string) ($contact['seniority'] ?? ''),
                is_array($contact['departments'] ?? null) ? implode(', ', $contact['departments']) : (string) ($contact['departments'] ?? ''),
                $sanitize ? '' : (string) ($contact['mobile_number'] ?? ''),
                $sanitize ? '' : (string) ($contact['direct_number'] ?? ''),
                (string) ($contact['person_linkedin_url'] ?? ''),
                (string) ($contact['city'] ?? ''),
                (string) ($contact['state'] ?? ''),
                (string) ($contact['country'] ?? ''),
                (string) ($contact['company'] ?? ''),
                (string) ($contact['website'] ?? '')
            ];
            
            // Only include non-empty rows
            if (implode('', $row) !== '') {
                fputcsv($out, $sanitize ? array_values(array_diff($row, ['work_email', 'personal_email', 'mobile_number', 'direct_number'])) : $row);
            }
        }

        rewind($out);
        return stream_get_contents($out);
    }

    public static function buildContactsCsvDynamic(array $contacts, bool $emailSelected, bool $phoneSelected): string
    {
        $contactsNorm = array_map(fn($c) => RecordNormalizer::normalizeContact(is_array($c) ? $c : ($c ? $c->toArray() : [])), $contacts);
        
        // Contact header schema - expanded to match Elasticsearch contact document structure
        $headers = [
            'id',
            'first_name',
            'last_name',
            'full_name',
            'title',
            'seniority',
            'departments',
            'email',
            'mobile_phone',
            'direct_phone',
            'linkedin_url',
            'person_city',
            'person_state',
            'person_country',
            'company_id',
            'company_name',
            'company_domain',
            'company_website',
            'company_industry',
            'company_employee_count',
            'company_linkedin_url',
            'company_facebook_url',
            'company_twitter_url',
            'company_phone',
            'company_location_street',
            'company_location_city',
            'company_location_state',
            'company_location_country',
            'company_location_postal_code',
            'company_location_formatted',
            'company_founded_year',
            'company_technologies',
            'company_keywords',
        ];
        
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);

        // If no checkboxes are selected, return only headers (no data rows)
        if (!$emailSelected && !$phoneSelected) {
            rewind($out);
            return stream_get_contents($out);
        }

        foreach ($contactsNorm as $c) {
            // Extract company location sub-fields if they exist
            $companyLocation = $c['company_location'] ?? $c['location'] ?? [];
            
            // Handle emails - only include if email field is selected, otherwise empty
            $email = '';
            if ($emailSelected) {
                $email = (string) (RecordNormalizer::getPrimaryEmail($c) ?? $c['email'] ?? $c['work_email'] ?? '');
            }
            
            // Handle phone numbers - only include if phone field is selected, otherwise empty
            $mobilePhone = '';
            $directPhone = '';
            if ($phoneSelected) {
                $mobilePhone = (string) ($c['mobile_phone'] ?? $c['mobile_number'] ?? '');
                $directPhone = (string) ($c['direct_phone'] ?? $c['direct_number'] ?? '');
            }
            
            $row = [
                (string) ($c['_id'] ?? $c['id'] ?? ''),
                (string) ($c['first_name'] ?? ''),
                (string) ($c['last_name'] ?? ''),
                (string) ($c['full_name'] ?? (($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))),
                (string) ($c['title'] ?? ''),
                (string) ($c['seniority'] ?? ''),
                is_array($c['departments'] ?? null) ? implode('; ', $c['departments']) : (string) ($c['departments'] ?? ''),
                $email,
                $mobilePhone,
                $directPhone,
                (string) ($c['linkedin_url'] ?? $c['person_linkedin_url'] ?? ''),
                (string) ($c['city'] ?? ''),
                (string) ($c['state'] ?? ''),
                (string) ($c['country'] ?? ''),
                (string) ($c['company_id'] ?? ''),
                (string) ($c['company'] ?? $c['company_name'] ?? ''),
                (string) ($c['domain'] ?? $c['company_domain'] ?? ''),
                (string) ($c['website'] ?? $c['company_website'] ?? ''),
                (string) ($c['industry'] ?? $c['company_industry'] ?? ''),
                (string) ($c['employee_count'] ?? $c['number_of_employees'] ?? $c['company_employee_count'] ?? ''),
                (string) ($c['company_linkedin_url'] ?? ''),
                (string) ($c['facebook_url'] ?? $c['company_facebook_url'] ?? ''),
                (string) ($c['twitter_url'] ?? $c['company_twitter_url'] ?? ''),
                (string) ($c['company_phone'] ?? $c['phone_number'] ?? ''),
                (string) ($companyLocation['street'] ?? $c['street'] ?? ''),
                (string) ($companyLocation['city'] ?? $c['company_city'] ?? ''),
                (string) ($companyLocation['state'] ?? $c['company_state'] ?? ''),
                (string) ($companyLocation['country'] ?? $c['company_country'] ?? ''),
                (string) ($companyLocation['postal_code'] ?? $c['postal_code'] ?? ''),
                (string) ($companyLocation['formatted'] ?? $c['address'] ?? ''),
                (string) ($c['founded_year'] ?? $c['company_founded_year'] ?? ''),
                is_array($c['technologies'] ?? null) ? implode('; ', $c['technologies']) : (string) ($c['technologies'] ?? ''),
                is_array($c['keywords'] ?? null) ? implode('; ', $c['keywords']) : (string) ($c['keywords'] ?? ''),
            ];

            if (implode('', $row) === '') {
                continue;
            }
            fputcsv($out, $row);
        }

        rewind($out);

        return stream_get_contents($out);
    }

    public static function buildCompaniesCsvDynamic(array $companies, array $contacts, bool $emailSelected, bool $phoneSelected): string
    {
        // Log incoming companies count
        Log::info('buildCompaniesCsvDynamic input', [
            'companies_count' => count($companies),
            'first_company_keys' => count($companies) > 0 ? array_keys($companies[0] ?? []) : [],
        ]);

        // Normalize companies only; contacts are intentionally ignored for company export
        $companiesNorm = array_map(fn($c) => RecordNormalizer::normalizeCompany(is_array($c) ? $c : ($c ? $c->toArray() : [])), $companies);

        // Debug counts to trace missing rows
        Log::info('buildCompaniesCsvDynamic', [
            'companies_in' => count($companies),
            'companies_norm' => count($companiesNorm),
        ]);

        $written = 0;

        // Company header schema - expanded to match Elasticsearch company document structure
        $headers = [
            'id',
            'domain',
            'name',
            'website',
            'business_category',
            'employee_count',
            'industry',
            'founded_year',
            'company_type',
            'linkedin_url',
            'facebook_url',
            'twitter_url',
            'phone_number',
            'technologies',
            'keywords',
            'location_street',
            'location_city',
            'location_state',
            'location_country',
            'location_postal_code',
            'location_region',
            'location_formatted',
            'sic_code',
            'naics_code',
            'description',
            'annual_revenue_usd',
            'total_funding_usd',
            'latest_funding_amount',
            'latest_funding_round',
            'last_funding_date',
        ];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);

        // If no checkboxes are selected, return only headers (no data rows)
        if (!$emailSelected && !$phoneSelected) {
            rewind($out);
            return stream_get_contents($out);
        }

        foreach ($companiesNorm as $company) {
            // Extract location sub-fields
            $location = $company['location'] ?? [];
            
            $row = [
                (string) ($company['_id'] ?? $company['id'] ?? ''),
                (string) ($company['domain'] ?? ''),
                (string) ($company['name'] ?? $company['company'] ?? ''),
                (string) ($company['website'] ?? ''),
                (string) ($company['business_category'] ?? ''),
                (string) ($company['employee_count'] ?? $company['number_of_employees'] ?? ''),
                (string) ($company['industry'] ?? ''),
                (string) ($company['founded_year'] ?? ''),
                (string) ($company['company_type'] ?? ''),
                (string) ($company['linkedin_url'] ?? ''),
                (string) ($company['facebook_url'] ?? ''),
                (string) ($company['twitter_url'] ?? ''),
                (string) ($company['phone_number'] ?? $company['company_phone'] ?? ''),
                is_array($company['technologies'] ?? null) ? implode('; ', $company['technologies']) : (string) ($company['technologies'] ?? ''),
                is_array($company['keywords'] ?? null) ? implode('; ', $company['keywords']) : (string) ($company['keywords'] ?? ''),
                (string) ($location['street'] ?? $company['street'] ?? ''),
                (string) ($location['city'] ?? $company['city'] ?? ''),
                (string) ($location['state'] ?? $company['state'] ?? ''),
                (string) ($location['country'] ?? $company['country'] ?? ''),
                (string) ($location['postal_code'] ?? $company['postal_code'] ?? ''),
                (string) ($location['region'] ?? ''),
                (string) ($location['formatted'] ?? $company['address'] ?? ''),
                (string) ($company['sic_code'] ?? ''),
                (string) ($company['naics_code'] ?? ''),
                (string) ($company['description'] ?? $company['short_description'] ?? ''),
                (string) ($company['annual_revenue_usd'] ?? ''),
                (string) ($company['total_funding_usd'] ?? ''),
                (string) ($company['latest_funding_amount'] ?? ''),
                (string) ($company['latest_funding_round'] ?? $company['latest_funding'] ?? ''),
                (string) ($company['last_funding_date'] ?? $company['last_raised_at'] ?? ''),
            ];
            
            fputcsv($out, $row);
            $written++;
        }

        Log::info('buildCompaniesCsvDynamic complete', ['companies_written' => $written]);

        rewind($out);
        return stream_get_contents($out);
    }

    // PII detection is now handled by the caller via $sanitize; the helpers
    // contactsHavePii/companiesHavePii are intentionally no-ops and can be
    // removed in a future cleanup without changing behaviour.

    public static function composeContactRowPii(array $c, bool $sanitize): array
    {
        $primaryEmail = $sanitize ? '' : (string) (RecordNormalizer::getPrimaryEmail($c) ?? '');
        $workEmail = $sanitize ? '' : (string) ($c['work_email'] ?? '');
        if ($workEmail === '' && $primaryEmail !== '') {
            $workEmail = $primaryEmail;
        }
        $personalEmail = $sanitize ? '' : (string) ($c['personal_email'] ?? '');
        if ($personalEmail === '' && !$sanitize) {
            $secondary = '';
            foreach (($c['emails'] ?? []) as $e) {
                $val = (string) ($e['email'] ?? '');
                if ($val !== '' && $val !== $workEmail) {
                    $secondary = $val;
                    break;
                }
            }
            $personalEmail = $secondary;
        }

        return [
            (string) ($c['domain'] ?? ''),
            (string) ($c['first_name'] ?? ''),
            (string) ($c['last_name'] ?? ''),
            (string) ($c['title'] ?? ''),
            $workEmail,
            $personalEmail,
            (string) ($c['seniority'] ?? ''),
            is_array($c['departments'] ?? null) ? implode(', ', $c['departments']) : (string) ($c['departments'] ?? ''),
            $sanitize ? '' : (string) ($c['mobile_number'] ?? ''),
            $sanitize ? '' : (string) ($c['direct_number'] ?? ''),
            (string) ($c['person_linkedin_url'] ?? ''),
            (string) ($c['city'] ?? ''),
            (string) ($c['state'] ?? ''),
            (string) ($c['country'] ?? ''),
        ];
    }

    public static function composeContactRowFree(array $c): array
    {
        return [
            (string) ($c['domain'] ?? ''),
            (string) ($c['first_name'] ?? ''),
            (string) ($c['last_name'] ?? ''),
            (string) ($c['title'] ?? ''),
            (string) ($c['seniority'] ?? ''),
            is_array($c['departments'] ?? null) ? implode(', ', $c['departments']) : (string) ($c['departments'] ?? ''),
            (string) ($c['person_linkedin_url'] ?? ''),
            (string) ($c['city'] ?? ''),
            (string) ($c['state'] ?? ''),
            (string) ($c['country'] ?? ''),
        ];
    }

    public static function composeContactRowDynamic(array $c, bool $emailSelected, bool $phoneSelected): array
    {
        $primaryEmail = $emailSelected ? (string) (RecordNormalizer::getPrimaryEmail($c) ?? '') : '';
        $workEmail = $emailSelected ? (string) ($c['work_email'] ?? '') : '';
        if ($workEmail === '' && $primaryEmail !== '') {
            $workEmail = $primaryEmail;
        }
        $personalEmail = $emailSelected ? (string) ($c['personal_email'] ?? '') : '';
        if ($emailSelected && $personalEmail === '') {
            $secondary = '';
            foreach ((($c['emails'] ?? []) ?: []) as $e) {
                $val = (string) ($e['email'] ?? '');
                if ($val !== '' && $val !== $workEmail) {
                    $secondary = $val;
                    break;
                }
            }
            $personalEmail = $secondary;
        }

        // Handle phone numbers with proper validation
        $mobileNumber = '';
        $directNumber = '';
        if ($phoneSelected) {
            $mobileNumber = (string) ($c['mobile_number'] ?? '');
            $directNumber = (string) ($c['direct_number'] ?? '');
            
            // If no phone data available, mark as "failure"
            if ($mobileNumber === '' && $directNumber === '') {
                $mobileNumber = 'failure';
                $directNumber = 'failure';
            }
        }

        // Handle emails with proper validation
        if ($emailSelected && $workEmail === '' && $personalEmail === '') {
            $workEmail = 'failure';
            $personalEmail = 'failure';
        }

        return [
            (string) ($c['domain'] ?? 'na'),
            (string) ($c['first_name'] ?? 'na'),
            (string) ($c['last_name'] ?? 'na'),
            (string) ($c['title'] ?? 'na'),
            $workEmail,
            $personalEmail,
            (string) ($c['seniority'] ?? 'na'),
            is_array($c['departments'] ?? null) ? implode(', ', $c['departments']) : (string) ($c['departments'] ?? 'na'),
            $mobileNumber,
            $directNumber,
            (string) ($c['person_linkedin_url'] ?? 'na'),
            (string) ($c['city'] ?? 'na'),
            (string) ($c['state'] ?? 'na'),
            (string) ($c['country'] ?? 'na'),
            (string) ($c['website'] ?? $c['domain'] ?? 'na'),
            (string) ($c['company'] ?? 'na'),
            (string) ($c['number_of_employees'] ?? 'na'),
            (string) ($c['industry'] ?? 'na'),
            (string) ($c['linkedin_url'] ?? 'na'),
            (string) ($c['facebook_url'] ?? 'na'),
            (string) ($c['twitter_url'] ?? 'na'),
            (string) ($c['street'] ?? 'na'),
            (string) ($c['city'] ?? 'na'),
            (string) ($c['state'] ?? 'na'),
            (string) ($c['country'] ?? 'na'),
            (string) ($c['postal_code'] ?? 'na'),
            (string) ($c['address'] ?? 'na'),
            is_array($c['keywords'] ?? null) ? implode('; ', $c['keywords']) : (string) ($c['keywords'] ?? 'na'),
            $phoneSelected ? (string) ($c['company_phone'] ?? $c['phone_number'] ?? 'failure') : '',
            is_array($c['technologies'] ?? null) ? implode('; ', $c['technologies']) : (string) ($c['technologies'] ?? 'na'),
            (string) ($c['total_funding_usd'] ?? 'na'),
            (string) ($c['latest_funding'] ?? 'na'),
            (string) ($c['latest_funding_amount'] ?? 'na'),
            (string) ($c['last_raised_at'] ?? 'na'),
            (string) ($c['annual_revenue_usd'] ?? 'na'),
            (string) ($c['sic_code'] ?? 'na'),
            (string) ($c['short_description'] ?? 'na'),
            (string) ($c['founded_year'] ?? 'na'),
        ];
    }

    public static function composeCompanyRowDynamic(array $comp, ?array $c, bool $emailSelected, bool $phoneSelected): array
    {
        $contactCity = (string) ($c['city'] ?? 'na');
        $contactState = (string) ($c['state'] ?? 'na');
        $contactCountry = (string) ($c['country'] ?? 'na');
        $companyCity = (string) ($comp['city'] ?? 'na');
        $companyState = (string) ($comp['state'] ?? 'na');
        $companyCountry = (string) ($comp['country'] ?? 'na');
        $keywords = is_array($comp['keywords'] ?? null) ? implode('; ', $comp['keywords']) : (string) ($comp['keywords'] ?? 'na');
        $techs = is_array($comp['technologies'] ?? null) ? implode('; ', $comp['technologies']) : (string) ($comp['technologies'] ?? 'na');

        $primaryEmail = $emailSelected ? (string) (RecordNormalizer::getPrimaryEmail($c ?? []) ?? '') : '';
        $workEmail = $emailSelected ? (string) (($c['work_email'] ?? '') ?: '') : '';
        if ($workEmail === '' && $primaryEmail !== '') {
            $workEmail = $primaryEmail;
        }
        $personalEmail = $emailSelected ? (string) (($c['personal_email'] ?? '') ?: '') : '';
        if ($emailSelected && $personalEmail === '') {
            $secondary = '';
            foreach ((($c['emails'] ?? []) ?: []) as $e) {
                $val = (string) ($e['email'] ?? '');
                if ($val !== '' && $val !== $workEmail) {
                    $secondary = $val;
                    break;
                }
            }
            $personalEmail = $secondary;
        }

        // Handle phone numbers with proper validation
        $mobileNumber = '';
        $directNumber = '';
        if ($phoneSelected) {
            $mobileNumber = (string) ($c['mobile_number'] ?? '');
            $directNumber = (string) ($c['direct_number'] ?? '');
            
            // If no phone data available, mark as "failure"
            if ($mobileNumber === '' && $directNumber === '') {
                $mobileNumber = 'failure';
                $directNumber = 'failure';
            }
        }

        // Handle emails with proper validation
        if ($emailSelected && $workEmail === '' && $personalEmail === '') {
            $workEmail = 'failure';
            $personalEmail = 'failure';
        }

        return [
            (string) ($c['domain'] ?? $comp['domain'] ?? 'na'),
            (string) ($c['first_name'] ?? 'na'),
            (string) ($c['last_name'] ?? 'na'),
            (string) ($c['title'] ?? 'na'),
            $workEmail,
            $personalEmail,
            (string) ($c['seniority'] ?? 'na'),
            is_array($c['departments'] ?? null) ? implode(', ', $c['departments']) : (string) ($c['departments'] ?? 'na'),
            $mobileNumber,
            $directNumber,
            (string) ($c['person_linkedin_url'] ?? 'na'),
            $contactCity,
            $contactState,
            $contactCountry,
            (string) ($comp['website'] ?? $comp['domain'] ?? 'na'),
            (string) ($comp['name'] ?? $comp['company'] ?? 'na'),
            (string) ($comp['number_of_employees'] ?? 'na'),
            (string) ($comp['industry'] ?? 'na'),
            (string) ($comp['linkedin_url'] ?? 'na'),
            (string) ($comp['facebook_url'] ?? 'na'),
            (string) ($comp['twitter_url'] ?? 'na'),
            (string) ($comp['street'] ?? 'na'),
            $companyCity,
            $companyState,
            $companyCountry,
            (string) ($comp['postal_code'] ?? 'na'),
            (string) ($comp['address'] ?? 'na'),
            $keywords,
            $phoneSelected ? (string) ($comp['phone_number'] ?? 'failure') : '',
            $techs,
            (string) ($comp['total_funding_usd'] ?? 'na'),
            '',
            '',
            '',
            (string) ($comp['annual_revenue_usd'] ?? 'na'),
            (string) ($comp['sic_code'] ?? 'na'),
            (string) ($comp['short_description'] ?? 'na'),
            (string) ($comp['founded_year'] ?? 'na'),
        ];
    }

    public static function composeCompanyRowFree(array $comp, ?array $c): array
    {
        $contactCity = (string) ($c['city'] ?? '');
        $contactState = (string) ($c['state'] ?? '');
        $contactCountry = (string) ($c['country'] ?? '');
        $companyCity = (string) ($comp['city'] ?? '');
        $companyState = (string) ($comp['state'] ?? '');
        $companyCountry = (string) ($comp['country'] ?? '');
        $keywords = is_array($comp['keywords'] ?? null) ? implode('; ', $comp['keywords']) : (string) ($comp['keywords'] ?? '');
        $techs = is_array($comp['technologies'] ?? null) ? implode('; ', $comp['technologies']) : (string) ($comp['technologies'] ?? '');

        return [
            (string) ($c['domain'] ?? $comp['domain'] ?? ''),
            (string) ($c['first_name'] ?? ''),
            (string) ($c['last_name'] ?? ''),
            (string) ($c['title'] ?? ''),
            (string) ($c['seniority'] ?? ''),
            is_array($c['departments'] ?? null) ? implode(', ', $c['departments']) : (string) ($c['departments'] ?? ''),
            (string) ($c['person_linkedin_url'] ?? ''),
            $contactCity,
            $contactState,
            $contactCountry,
            (string) ($comp['name'] ?? $comp['company'] ?? ''),
            (string) ($comp['number_of_employees'] ?? ''),
            (string) ($comp['industry'] ?? ''),
            (string) ($comp['linkedin_url'] ?? ''),
            (string) ($comp['facebook_url'] ?? ''),
            (string) ($comp['twitter_url'] ?? ''),
            (string) ($comp['street'] ?? ''),
            $companyCity,
            $companyState,
            $companyCountry,
            (string) ($comp['postal_code'] ?? ''),
            (string) ($comp['address'] ?? ''),
            $keywords,
            $techs,
            (string) ($comp['total_funding_usd'] ?? ''),
            '', // latest_funding
            '', // latest_funding_amount
            '', // last_raised_at
            (string) ($comp['annual_revenue_usd'] ?? ''),
            (string) ($comp['sic_code'] ?? ''),
            (string) ($comp['short_description'] ?? ''),
            (string) ($comp['founded_year'] ?? ''),
        ];
    }

    public static function buildCompaniesCsv(array $companies, array $contacts, bool $sanitize = false): string
    {
        // Log input for debugging
        error_log('Input companies count: ' . count($companies));
        
        $companiesNorm = [];
        foreach ($companies as $company) {
            $companyData = is_array($company) ? $company : ($company ? $company->toArray() : []);
            $normalized = RecordNormalizer::normalizeCompany($companyData);
            error_log('Processing company: ' . ($normalized['name'] ?? $normalized['company'] ?? 'unknown') . ' (domain: ' . ($normalized['domain'] ?? 'none') . ')' . ' (website: ' . ($normalized['website'] ?? 'none') . ')');
            $companiesNorm[] = $normalized;
        }
        
        $headers = [
            'domain',
            'name',
            'website',
            'number_of_employees',
            'industry',
            'linkedin_url',
            'facebook_url',
            'twitter_url',
            'street',
            'city',
            'state',
            'country',
            'postal_code',
            'address',
            'phone_number',
            'keywords',
            'technologies',
            'total_funding_usd',
            'annual_revenue_usd',
            'sic_code',
            'short_description',
            'founded_year',
            'raw_id' // Added for debugging
        ];
        
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);

        $exportedCount = 0;
        foreach ($companiesNorm as $company) {
            $row = [
                (string) ($company['domain'] ?? ''),
                (string) ($company['name'] ?? $company['company'] ?? ''),
                (string) ($company['website'] ?? ''),
                (string) ($company['number_of_employees'] ?? ''),
                (string) ($company['industry'] ?? ''),
                (string) ($company['linkedin_url'] ?? ''),
                (string) ($company['facebook_url'] ?? ''),
                (string) ($company['twitter_url'] ?? ''),
                (string) ($company['street'] ?? ''),
                (string) ($company['city'] ?? ''),
                (string) ($company['state'] ?? ''),
                (string) ($company['country'] ?? ''),
                (string) ($company['postal_code'] ?? ''),
                (string) ($company['address'] ?? ''),
                (string) ($company['phone_number'] ?? ''),
                is_array($company['keywords'] ?? null) ? implode('; ', $company['keywords']) : (string) ($company['keywords'] ?? ''),
                is_array($company['technologies'] ?? null) ? implode('; ', $company['technologies']) : (string) ($company['technologies'] ?? ''),
                (string) ($company['total_funding_usd'] ?? ''),
                (string) ($company['annual_revenue_usd'] ?? ''),
                (string) ($company['sic_code'] ?? ''),
                (string) ($company['short_description'] ?? ''),
                (string) ($company['founded_year'] ?? ''),
                (string) ($company['_id'] ?? $company['id'] ?? '') // Add raw ID for debugging
            ];
            
            fputcsv($out, $row);
            $exportedCount++;
        }
        
        error_log('Exported ' . $exportedCount . ' companies to CSV');

        rewind($out);
        return stream_get_contents($out);
    }

    public static function composeCompanyRowPii(array $comp, ?array $c, bool $sanitize): array
    {
        $contactCity = (string) ($c['city'] ?? '');
        $contactState = (string) ($c['state'] ?? '');
        $contactCountry = (string) ($c['country'] ?? '');
        $companyCity = (string) ($comp['city'] ?? '');
        $companyState = (string) ($comp['state'] ?? '');
        $companyCountry = (string) ($comp['country'] ?? '');
        $keywords = is_array($comp['keywords'] ?? null) ? implode('; ', $comp['keywords']) : (string) ($comp['keywords'] ?? '');
        $techs = is_array($comp['technologies'] ?? null) ? implode('; ', $comp['technologies']) : (string) ($comp['technologies'] ?? '');

        $primaryEmail = $sanitize ? '' : (string) (RecordNormalizer::getPrimaryEmail($c ?? []) ?? '');
        $workEmail = $sanitize ? '' : (string) (($c['work_email'] ?? '') ?: '');
        if ($workEmail === '' && $primaryEmail !== '') {
            $workEmail = $primaryEmail;
        }
        $personalEmail = $sanitize ? '' : (string) (($c['personal_email'] ?? '') ?: '');
        if (!$sanitize && $personalEmail === '') {
            $secondary = '';
            foreach ((($c['emails'] ?? []) ?: []) as $e) {
                $val = (string) ($e['email'] ?? '');
                if ($val !== '' && $val !== $workEmail) {
                    $secondary = $val;
                    break;
                }
            }
            $personalEmail = $secondary;
        }

        return [
            (string) ($c['domain'] ?? $comp['domain'] ?? ''),
            (string) ($c['first_name'] ?? ''),
            (string) ($c['last_name'] ?? ''),
            (string) ($c['title'] ?? ''),
            $workEmail,
            $personalEmail,
            (string) ($c['seniority'] ?? ''),
            is_array($c['departments'] ?? null) ? implode(', ', $c['departments']) : (string) ($c['departments'] ?? ''),
            $sanitize ? '' : (string) ($c['mobile_number'] ?? ''),
            $sanitize ? '' : (string) ($c['direct_number'] ?? ''),
            (string) ($c['person_linkedin_url'] ?? ''),
            $contactCity,
            $contactState,
            $contactCountry,
            (string) ($comp['name'] ?? $comp['company'] ?? ''),
            (string) ($comp['number_of_employees'] ?? ''),
            (string) ($comp['industry'] ?? ''),
            (string) ($comp['linkedin_url'] ?? ''),
            (string) ($comp['facebook_url'] ?? ''),
            (string) ($comp['twitter_url'] ?? ''),
            (string) ($comp['street'] ?? ''),
            $companyCity,
            $companyState,
            $companyCountry,
            (string) ($comp['postal_code'] ?? ''),
            (string) ($comp['address'] ?? ''),
            $keywords,
            $sanitize ? '' : (string) ($comp['phone_number'] ?? ''),
            $techs,
            (string) ($comp['total_funding_usd'] ?? ''),
            '',
            '',
            '',
            (string) ($comp['annual_revenue_usd'] ?? ''),
            (string) ($comp['sic_code'] ?? ''),
            (string) ($comp['short_description'] ?? ''),
            (string) ($comp['founded_year'] ?? ''),
        ];
    }
}
