<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AiController extends Controller
{
    public function generateFilters(Request $request)
    {
        $request->headers->set('Accept', 'application/json');
        $mode = (string) $request->input('mode', '');
        $context = (string) $request->input('context', 'company');
        $query = trim((string) ($request->input('query') ?? ''));
        $prompt = trim((string) ($request->input('prompt') ?? ''));

        if ($mode !== 'modify_filters' && $query === '' && $prompt === '') {
            return response()->json(['error' => 'missing_input'], 422);
        }

        if ($mode === 'modify_filters') {
            $current = (array) $request->input('current_filters', []);
            $instruction = (string) $request->input('instruction', '');
            $text = $instruction !== '' ? $instruction : ($query !== '' ? $query : $prompt);
            $generated = $this->parseCanonical($text, $context);
            $merged = $this->mergeFilters($current, $generated);

            return response()->json(['filters' => $merged, 'model' => 'local'], 200);
        }

        $text = $query !== '' ? $query : $prompt;
        $canonical = $this->parseCanonical($text, $context);

        return response()->json(['filters' => $canonical, 'model' => 'local'], 200);
    }

    public function contactSummary(Request $request)
    {
        // STEP 1D: Always return 200, never 422
        try {
            $payload = (array) $request->input('contact', []);
            if ($payload === []) {
                // Return empty summary instead of 422
                return response()->json([
                    'summary' => '',
                    'model' => 'local',
                ], 200);
            }

            return response()->json([
                'summary' => $this->summarizeContact($payload),
                'model' => 'local',
            ], 200);
            
        } catch (\Throwable $e) {
            return response()->json([
                'summary' => '',
                'model' => 'local',
            ], 200);
        }
    }

    public function companySummary(Request $request)
    {
        // STEP 1D: Always return 200, never 422
        try {
            $payload = (array) $request->input('company', []);
            if ($payload === []) {
                // Return empty summary instead of 422
                return response()->json([
                    'summary' => '',
                    'model' => 'local',
                ], 200);
            }

            return response()->json([
                'summary' => $this->summarizeCompany($payload),
                'model' => 'local',
            ], 200);
            
        } catch (\Throwable $e) {
            return response()->json([
                'summary' => '',
                'model' => 'local',
            ], 200);
        }
    }

    // ... [All the protected methods remain exactly the same - no changes needed]
    
    protected function normalizeText(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    protected function parseCanonical(string $input, string $context): array
    {
        // ... [existing code remains the same]
        $t = $this->normalizeText($input);
        $fields = [
            'company.name',
            'company.domain',
            'company.industry',
            'company.country',
            'company.size',
            'title',
            'seniority',
            'department',
            'location.country',
            'location.state',
            'location.city',
            // Contact-specific name fields
            'first_name',
            'last_name',
        ];
        $out = [];

        if (preg_match('/\b([a-z0-9\-]+\.[a-z]{2,})\b/', $input, $md)) {
            $out['company.domain'] = $md[1];
        }

        $titles = ['cto', 'ceo', 'cfo', 'coo', 'cio', 'developer', 'engineer', 'manager', 'designer', 'analyst', 'consultant'];
        foreach ($titles as $ti) {
            if (preg_match('/\b' . preg_quote($ti, '/') . 's?\b/', $t)) {
                $out['title'] = $ti;
                break;
            }
        }

        $departments = ['engineering', 'marketing', 'sales', 'hr', 'product', 'design'];
        foreach ($departments as $d) {
            if (preg_match('/\b' . preg_quote($d, '/') . '\b/', $t)) {
                $out['department'] = $d;
                break;
            }
        }

        $countries = ['india', 'usa', 'united states', 'uk', 'canada', 'germany', 'france'];
        foreach ($countries as $c) {
            if (preg_match('/\b' . preg_quote($c, '/') . '\b/', $t)) {
                $out['location.country'] = $c;
                break;
            }
        }

        $states = ['california', 'texas', 'new york'];
        foreach ($states as $s) {
            if (preg_match('/\b' . preg_quote($s, '/') . '\b/', $t)) {
                $out['location.state'] = $s;
                break;
            }
        }

        if (preg_match('/\bat\s+([a-z][a-z0-9 ]{1,})\b/', $t, $mc)) {
            $out['company.name'] = trim($mc[1]);
        }

        if (preg_match('/\bindustry\b\s*[:\-]?\s*([a-z ]+)/', $t, $mi)) {
            $out['company.industry'] = trim($mi[1]);
        }

        // Contact name parsing (first/last name and generic name)
        // Always parse name cues so the UI can map appropriately based on page context
        // Explicit first name
        if (preg_match('/\bfirst\s+name\b\s*[:\-]?\s*([a-zA-Z][a-zA-Z\s\'\-]+)/', $input, $mf)) {
            $out['first_name'] = trim($mf[1]);
        }
        // Explicit last name
        if (preg_match('/\blast\s+name\b\s*[:\-]?\s*([a-zA-Z][a-zA-Z\s\'\-]+)/', $input, $ml)) {
            $out['last_name'] = trim($ml[1]);
        }
        // Generic "name" cue (e.g., "search contact name sujata")
        if (!isset($out['first_name']) && !isset($out['last_name'])) {
            if (preg_match('/\b(contact\s+)?name\b\s*[:\-]?\s*([a-zA-Z][a-zA-Z\s\'\-]+)/', $input, $mn)) {
                $raw = trim($mn[2]);
                $parts = preg_split('/\s+/', $raw);
                if ($parts && count($parts) > 1) {
                    $out['first_name'] = $parts[0];
                    $out['last_name'] = $parts[count($parts) - 1];
                } elseif ($raw !== '') {
                    $out['first_name'] = $raw;
                }
            }
        }

        $final = [];
        foreach ($out as $field => $value) {
            if (in_array($field, $fields, true)) {
                if ($field === 'company.domain') {
                    $final[$field] = strtolower(trim((string) $value));
                } else {
                    $final[$field] = $this->normalizeText((string) $value);
                }
            }
        }

        $items = [];
        foreach ($final as $field => $value) {
            $mapped = $field;
            if ($field === 'location.state') {
                $mapped = 'state';
            } elseif ($field === 'company.name') {
                $mapped = 'company';
            } elseif ($field === 'first_name') {
                $mapped = 'first_name';
            } elseif ($field === 'last_name') {
                $mapped = 'last_name';
            }
            $items[] = ['field' => $mapped, 'operator' => '=', 'value' => $value];
        }
        usort($items, fn($a, $b) => strcmp($a['field'], $b['field']));

        return $items;
    }

    protected function mergeFilters(array $current, array $generated): array
    {
        $map = [];
        foreach ($current as $f) {
            $field = (string) ($f['field'] ?? '');
            $value = (string) ($f['value'] ?? '');
            if ($field !== '') {
                $map[$field] = $this->normalizeText($value);
            }
        }
        foreach ($generated as $g) {
            $field = (string) ($g['field'] ?? '');
            $value = (string) ($g['value'] ?? '');
            if ($field !== '') {
                $map[$field] = $this->normalizeText($value);
            }
        }
        $items = [];
        foreach ($map as $field => $value) {
            $items[] = ['field' => $field, 'operator' => '=', 'value' => $value];
        }
        usort($items, fn($a, $b) => strcmp($a['field'], $b['field']));

        return $items;
    }

    protected function summarizeContact(array $contact): string
    {
        $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: ($contact['full_name'] ?? 'This contact');
        $role = $contact['title'] ?? null;
        $company = $contact['company'] ?? null;
        $location = Arr::get($contact, 'location.country') ?? Arr::get($contact, 'location.city');
        $skills = $contact['skills'] ?? [];
        $skills = is_array($skills) ? array_slice(array_filter(array_map('strval', $skills)), 0, 3) : [];

        $parts = [];
        $parts[] = sprintf(
            '%s is a%s%s professional',
            $name,
            $role ? ' ' . $role : '',
            $company ? ' at ' . $company : ''
        );
        if ($location) {
            $parts[] = 'based in ' . Str::title($location) . '.';
        } else {
            $parts[count($parts) - 1] .= '.';
        }

        if (!empty($skills)) {
            $parts[] = 'Key expertise: ' . implode(', ', array_map(fn($s) => Str::title($s), $skills)) . '.';
        }

        $seniority = $contact['seniority'] ?? $contact['seniority_level'] ?? null;
        if ($seniority) {
            $parts[] = 'Seniority level: ' . Str::title((string) $seniority) . '.';
        }

        return trim(implode(' ', $parts));
    }

    protected function summarizeCompany(array $company): string
    {
        $name = $company['company'] ?? $company['name'] ?? 'This company';
        $industry = $company['business_category'] ?? $company['industry'] ?? null;
        $employees = $company['employee_count'] ?? $company['number_of_employees'] ?? null;
        $revenue = $company['annual_revenue_usd'] ?? null;
        $founded = $company['founded_year'] ?? null;
        $location = Arr::get($company, 'location.country') ?? Arr::get($company, 'location.city');

        $parts = [];
        $parts[] = sprintf(
            '%s operates%s%s.',
            $name,
            $industry ? ' in the ' . $industry . ' sector' : '',
            $location ? ' with presence in ' . Str::title($location) : ''
        );

        if ($employees) {
            $parts[] = 'Approximate team size: ' . (is_numeric($employees) ? number_format((int) $employees) : $employees) . '.';
        }

        if ($revenue) {
            $formatted = is_numeric($revenue) ? '$' . number_format((int) $revenue) : $revenue;
            $parts[] = 'Estimated annual revenue: ' . $formatted . '.';
        }

        if ($founded) {
            $parts[] = 'Founded in ' . $founded . '.';
        }

        $technologies = $company['company_technologies'] ?? $company['technologies'] ?? [];
        $technologies = is_array($technologies) ? array_slice(array_filter(array_map('strval', $technologies)), 0, 5) : [];
        if ($technologies) {
            $parts[] = 'Notable technologies: ' . implode(', ', $technologies) . '.';
        }

        $description = $company['business_description'] ?? $company['short_description'] ?? null;
        if ($description) {
            $parts[] = Str::limit(trim((string) $description), 240);
        }

        return trim(implode(' ', $parts));
    }
}
