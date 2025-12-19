<?php

namespace App\Services;

class RecordNormalizer
{
    public static function normalizeContact(array $doc): array
    {
        $id = $doc['_id'] ?? $doc['id'] ?? null;
        $domain = $doc['domain'] ?? $doc['website'] ?? data_get($doc, 'company_domain');

        // Emails: unify from multiple sources
        $emails = [];
        $rawEmails = $doc['emails'] ?? [];
        if (is_array($rawEmails)) {
            foreach ($rawEmails as $e) {
                if (is_array($e) && !empty($e['email'])) {
                    $emails[] = ['email' => trim($e['email'])];
                } elseif (is_string($e) && trim($e) !== '') {
                    $emails[] = ['email' => trim($e)];
                }
            }
        }
        foreach (['work_email', 'personal_email', 'email'] as $ek) {
            if (!empty($doc[$ek])) {
                $val = is_array($doc[$ek]) ? ($doc[$ek]['email'] ?? null) : $doc[$ek];
                if ($val && !self::containsEmail($emails, $val)) {
                    $emails[] = ['email' => trim((string) $val)];
                }
            }
        }

        // Phones: unify from arrays and singular fields
        $phones = [];
        $rawPhones = $doc['phone_numbers'] ?? $doc['phones'] ?? [];
        if (is_array($rawPhones)) {
            foreach ($rawPhones as $p) {
                if (is_array($p) && !empty($p['phone_number'])) {
                    $phones[] = ['phone_number' => trim($p['phone_number'])];
                } elseif (is_string($p) && trim($p) !== '') {
                    $phones[] = ['phone_number' => trim($p)];
                }
            }
        } elseif (is_string($rawPhones) && trim($rawPhones) !== '') {
            foreach (explode(';', $rawPhones) as $p) {
                if (trim($p) !== '') {
                    $phones[] = ['phone_number' => trim($p)];
                }
            }
        }

        foreach (['mobile_number', 'direct_number', 'phone', 'phone_number', 'mobile_phone'] as $pk) {
            if (!empty($doc[$pk])) {
                $val = is_array($doc[$pk]) ? ($doc[$pk]['phone_number'] ?? null) : $doc[$pk];
                if ($val && !self::containsPhone($phones, (string) $val)) {
                    $phones[] = ['phone_number' => trim((string) $val)];
                }
            }
        }

        // Name and role
        $first = $doc['first_name'] ?? null;
        $last = $doc['last_name'] ?? null;
        $full = $doc['full_name'] ?? trim(implode(' ', array_filter([$first, $last])));
        $title = $doc['title'] ?? null;

        // Social
        $linkedin = $doc['person_linkedin_url'] ?? $doc['linkedin_url'] ?? null;
        if (is_string($linkedin)) {
            $linkedin = trim($linkedin);
            $linkedin = preg_replace('/^[`\s]+|[`\s]+$/', '', $linkedin);
        }

        // Location
        $city = $doc['city'] ?? data_get($doc, 'location.city');
        $state = $doc['state'] ?? data_get($doc, 'location.state');
        $country = $doc['country'] ?? data_get($doc, 'location.country');

        // Company
        $company = $doc['company'] ?? $doc['name'] ?? null;
        $website = $doc['website'] ?? $domain;

        // Seniority/departments
        $seniority = $doc['seniority'] ?? $doc['seniority_level'] ?? null;
        $departments = $doc['departments'] ?? ($doc['department'] ?? null);
        if (is_string($departments)) {
            $departments = [$departments];
        }

        return [
            'id' => $id,
            'domain' => $domain,
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $full,
            'title' => $title,
            'work_email' => $doc['work_email'] ?? null,
            'personal_email' => $doc['personal_email'] ?? null,
            'emails' => $emails,
            'phones' => $phones,
            'mobile_number' => $doc['mobile_number'] ?? null,
            'direct_number' => $doc['direct_number'] ?? null,
            'person_linkedin_url' => $linkedin,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'seniority' => $seniority,
            'departments' => $departments,
            'company' => $company,
            'website' => $website,
        ];
    }

    public static function normalizeCompany(array $doc): array
    {
        $domain = $doc['domain'] ?? $doc['website'] ?? data_get($doc, 'company_domain');
        $name = $doc['name'] ?? $doc['company'] ?? null;
        $linkedin = $doc['linkedin_url'] ?? $doc['company_linkedin_url'] ?? null;
        if (is_string($linkedin)) {
            $linkedin = trim($linkedin);
            $linkedin = preg_replace('/^[`\s]+|[`\s]+$/', '', $linkedin);
        }
        $facebook = data_get($doc, 'social_media.facebook_url') ?? $doc['facebook_url'] ?? null;
        $twitter = data_get($doc, 'social_media.twitter_url') ?? $doc['twitter_url'] ?? null;
        $phone = $doc['phone_number'] ?? $doc['company_phone'] ?? null;

        $emails = [];
        $rawEmails = $doc['emails'] ?? [];
        if (is_array($rawEmails)) {
            foreach ($rawEmails as $e) {
                if (is_array($e) && !empty($e['email'])) {
                    $emails[] = ['email' => trim($e['email'])];
                } elseif (is_string($e) && trim($e) !== '') {
                    $emails[] = ['email' => trim($e)];
                }
            }
        }
        $primaryEmail = $doc['company_email'] ?? ($doc['email'] ?? null);
        if (!empty($primaryEmail)) {
            $val = is_array($primaryEmail) ? ($primaryEmail['email'] ?? null) : $primaryEmail;
            if ($val) {
                $exists = false;
                foreach ($emails as $e) {
                    if (isset($e['email']) && strtolower(trim($e['email'])) === strtolower(trim((string) $val))) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $emails[] = ['email' => trim((string) $val)];
                }
            }
        }

        $street = $doc['street'] ?? data_get($doc, 'location.street');
        $city = $doc['city'] ?? data_get($doc, 'location.city');
        $state = $doc['state'] ?? data_get($doc, 'location.state');
        $country = $doc['country'] ?? data_get($doc, 'location.country');
        $postal = $doc['postal_code'] ?? data_get($doc, 'location.postal_code');
        $address = $doc['address'] ?? implode(', ', array_filter([$street, $city, $state, $postal, $country]));

        $employees = $doc['number_of_employees'] ?? $doc['employee_count'] ?? null;
        if (is_string($employees)) {
            $employees = (int) preg_replace('/[^0-9]/', '', $employees);
        }

        $technologies = $doc['technologies'] ?? $doc['company_technologies'] ?? $doc['tech_stack'] ?? [];
        if (is_string($technologies)) {
            $technologies = [$technologies];
        }

        $keywords = $doc['keywords'] ?? [];
        if (is_string($keywords)) {
            $keywords = [$keywords];
        }

        // Revenue: support both annual_revenue_usd and annual_revenue (string/number)
        $annualRevenue = $doc['annual_revenue_usd'] ?? ($doc['annual_revenue'] ?? null);
        if (is_string($annualRevenue)) {
            $annualRevenue = (int) preg_replace('/[^0-9]/', '', $annualRevenue);
        }

        // Funding: support nested funding object and top-level fields
        $fundingObj = is_array($doc['funding'] ?? null) ? ($doc['funding'] ?? []) : [];
        $totalFunding = $doc['total_funding_usd'] ?? ($fundingObj['total_funding'] ?? null);
        if (is_string($totalFunding)) {
            $totalFunding = (int) preg_replace('/[^0-9]/', '', $totalFunding);
        }
        $latestFunding = $doc['latest_funding'] ?? ($fundingObj['latest_funding'] ?? null);
        $latestFundingAmount = $doc['latest_funding_amount'] ?? ($fundingObj['latest_funding_amount'] ?? null);
        if (is_string($latestFundingAmount)) {
            $latestFundingAmount = (int) preg_replace('/[^0-9]/', '', $latestFundingAmount);
        }
        $lastRaisedAt = $doc['last_raised_at'] ?? ($fundingObj['last_raised_at'] ?? null);

        return [
            'domain' => $domain,
            'website' => $domain,
            'name' => $name,
            'company' => $name,
            'number_of_employees' => $employees,
            'industry' => $doc['industry'] ?? $doc['business_category'] ?? null,
            'linkedin_url' => $linkedin,
            'company_linkedin_url' => $linkedin,
            'facebook_url' => $facebook,
            'twitter_url' => $twitter,
            'social_media' => [
                'facebook_url' => $facebook,
                'twitter_url' => $twitter,
            ],
            'work_email' => is_array($primaryEmail) ? ($primaryEmail['email'] ?? null) : $primaryEmail,
            'emails' => $emails,
            'phone_number' => $phone,
            'company_phone' => $phone,
            'street' => $street,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'postal_code' => $postal,
            'address' => $address,
            'location' => [
                'street' => $street,
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'postal_code' => $postal,
            ],
            'keywords' => $keywords,
            'technologies' => $technologies,
            'total_funding_usd' => $totalFunding,
            'annual_revenue_usd' => $annualRevenue,
            'founded_year' => $doc['founded_year'] ?? null,
            'short_description' => $doc['short_description'] ?? $doc['business_description'] ?? null,
            'sic_code' => $doc['sic_code'] ?? null,
            'latest_funding' => $latestFunding,
            'latest_funding_amount' => $latestFundingAmount,
            'last_raised_at' => $lastRaisedAt,
        ];
    }

    public static function getPrimaryEmail($contact): ?string
    {
        if (is_object($contact) && method_exists($contact, 'toArray')) {
            $contact = $contact->toArray();
        }
        $norm = self::normalizeContact(is_array($contact) ? $contact : (array) $contact);
        if (!empty($norm['work_email'])) {
            return (string) $norm['work_email'];
        }
        foreach ($norm['emails'] as $e) {
            $val = $e['email'] ?? null;
            if ($val) {
                return (string) $val;
            }
        }

        return null;
    }

    public static function getPrimaryPhone($contact): ?string
    {
        if (is_object($contact) && method_exists($contact, 'toArray')) {
            $contact = $contact->toArray();
        }
        $norm = self::normalizeContact(is_array($contact) ? $contact : (array) $contact);
        foreach ($norm['phones'] as $p) {
            $val = $p['phone_number'] ?? null;
            if ($val) {
                return (string) $val;
            }
        }

        return null;
    }

    public static function hasEmail(array $contact): bool
    {
        return self::getPrimaryEmail($contact) !== null;
    }

    public static function hasPhone(array $contact): bool
    {
        return self::getPrimaryPhone($contact) !== null;
    }

    public static function normalizeTechnology(string $tech): string
    {
        $tech = trim(mb_strtolower($tech));
        if ($tech === '') {
            return '';
        }

        $map = [
            'amazon web services' => 'aws',
            'aws cloud' => 'aws',
            'google cloud' => 'gcp',
            'google cloud platform' => 'gcp',
            'microsoft azure' => 'azure',
            'reactjs' => 'react',
            'react.js' => 'react',
            'vuejs' => 'vue',
            'angularjs' => 'angular',
            'node' => 'nodejs',
            'node.js' => 'nodejs',
            'php7' => 'php',
            'php8' => 'php',
            'wp' => 'wordpress',
            'wordpress cms' => 'wordpress',
            'shopify plus' => 'shopify',
            'sf' => 'salesforce',
            'salesforce crm' => 'salesforce',
            'hubspot crm' => 'hubspot',
            'mongo' => 'mongodb',
            'my sql' => 'mysql',
            'postgres' => 'postgresql',
        ];

        return $map[$tech] ?? $tech;
    }

    public static function normalizeTechnologies(array $techs): array
    {
        $normalized = [];
        foreach ($techs as $tech) {
            $n = self::normalizeTechnology((string) $tech);
            if ($n !== '') {
                $normalized[] = $n;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function containsEmail(array $arr, string $email): bool
    {
        foreach ($arr as $e) {
            if (isset($e['email']) && strtolower(trim($e['email'])) === strtolower(trim($email))) {
                return true;
            }
        }

        return false;
    }

    private static function containsPhone(array $arr, string $phone): bool
    {
        $clean = preg_replace('/\D+/', '', $phone);
        foreach ($arr as $p) {
            $pv = preg_replace('/\D+/', '', (string) ($p['phone_number'] ?? ''));
            if ($pv === $clean) {
                return true;
            }
        }

        return false;
    }
}
