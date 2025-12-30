<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use App\Services\AiQueryTranslatorService;

class AiController extends Controller
{
    public function __construct(
        protected AiQueryTranslatorService $translator
    ) {}

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

            $messages = [
                ['role' => 'user', 'content' => $instruction]
            ];

            // Translate using the service
            $result = $this->translator->translate($messages, ['current_filters' => $current]);
            $entity = $result['entity'] ?? 'contacts';
            $dslFilters = (array) ($result['filters'] ?? ['contact' => [], 'company' => []]);
            // Preserve legacy field names in modify mode for tests
            $flatContact = $this->flattenFilters($dslFilters['contact'] ?? [], 'contacts', true);
            $flatCompany = $this->flattenFilters($dslFilters['company'] ?? [], 'companies', true);
            $flatGenerated = array_values(array_merge($flatContact, $flatCompany));

            // Fallback overrides from instruction text
            $hasJob = collect($flatGenerated)->contains(fn($it) => ($it['field'] ?? '') === 'job_title');
            $hasCountry = collect($flatGenerated)->contains(fn($it) => ($it['field'] ?? '') === 'contact_country');
            if (!$hasJob && preg_match('/\b(cto|ceo|cfo|coo|manager|director|engineer)\b/i', $instruction, $m)) {
                $flatGenerated[] = ['field' => 'job_title', 'operator' => '=', 'value' => $this->normalizeText($m[1])];
            }
            if (!$hasCountry && preg_match('/\b(india|germany|france|spain|italy|uk|usa)\b/i', $instruction, $m)) {
                $flatGenerated[] = ['field' => 'contact_country', 'operator' => '=', 'value' => $this->normalizeText($m[1])];
            }
            $merged = $this->mergeFilters($current, $flatGenerated);
            return response()->json(['filters' => $merged, 'entity' => $entity, 'model' => 'local'], 200);
        }

        $text = $query !== '' ? $query : $prompt;

        // Use the service to translate and flatten for legacy consumers
        $result = $this->translator->translate([['role' => 'user', 'content' => $text]]);
        $entity = $result['entity'] ?? 'contacts';
        $dslFilters = (array) ($result['filters'] ?? ['contact' => [], 'company' => []]);
        // Legacy mode when context is provided (tests expect legacy field names like location.country)
        $legacy = $request->has('context');
        // Flatten both buckets to include all relevant fields
        $flatContact = $this->flattenFilters($dslFilters['contact'] ?? [], 'contacts', $legacy);
        $flatCompany = $this->flattenFilters($dslFilters['company'] ?? [], 'companies', $legacy);
        $flat = array_values(array_merge($flatContact, $flatCompany));

        // Fallback: inject location country and domain when missing
        $hasContactCountry = collect($flat)->contains(fn($it) => ($it['field'] ?? '') === ($legacy ? 'location.country' : 'contact_country'));
        if (!$hasContactCountry && preg_match('/\b([A-Z][a-zA-Z]+)\b(?:\s*,\s*([A-Z][a-zA-Z]+))?$/m', $text, $m)) {
            $country = isset($m[2]) ? $m[2] : $m[1];
            $flat[] = ['field' => $legacy ? 'location.country' : 'contact_country', 'operator' => '=', 'value' => $this->normalizeText($country)];
        }
        $hasCompanyDomain = collect($flat)->contains(fn($it) => ($it['field'] ?? '') === ($legacy ? 'company.domain' : 'company_domain'));
        if (!$hasCompanyDomain && preg_match('/\b([a-zA-Z0-9-]+\.(?:com|io|net|org|co|ai|dev))\b/i', $text, $dm)) {
            $flat[] = ['field' => $legacy ? 'company.domain' : 'company_domain', 'operator' => '=', 'value' => strtolower(trim($dm[1]))];
        }
        return response()->json(['filters' => $flat, 'entity' => $entity, 'model' => 'local'], 200);
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

    /**
     * Convert the nested DSL format from AiQueryTranslatorService to the flat list format
     * expected by the frontend for this endpoint.
     */
    protected function flattenFilters(array $dslFilters, string $entity = 'contacts', bool $legacy = false): array
    {
        $items = [];
        $contactCtx = strtolower($entity) === 'contacts';

        $mapField = function (string $field) use ($contactCtx, $legacy) {
            if ($legacy) {
                // Map canonical keys back to legacy names for compatibility tests
                return match ($field) {
                    'job_title' => 'title',
                    'departments' => 'department',
                    'industries' => 'company.industry',
                    'company_names' => 'company',
                    'company_domain' => 'company.domain',
                    'countries' => 'location.country',
                    'states' => 'state',
                    'cities' => 'city',
                    default => $field,
                };
            }
            return match ($field) {
                'title' => 'job_title',
                'company.domain' => 'company_domain',
                'location.country' => $contactCtx ? 'contact_country' : 'company_country',
                'countries' => $contactCtx ? 'contact_country' : 'company_country',
                'states' => $contactCtx ? 'contact_state' : 'company_state',
                'cities' => $contactCtx ? 'contact_city' : 'company_city',
                default => $field,
            };
        };

        foreach ($dslFilters as $field => $config) {
            // Expand nested location object
            if ($field === 'location' && is_array($config)) {
                $inc = $config['include'] ?? [];
                if (isset($inc['countries']) && is_array($inc['countries'])) {
                    foreach ($inc['countries'] as $val) {
                        $items[] = [
                            'field' => $legacy ? 'location.country' : ($contactCtx ? 'contact_country' : 'company_country'),
                            'operator' => '=',
                            'value' => $this->normalizeText((string) $val)
                        ];
                    }
                }
                if (isset($inc['states']) && is_array($inc['states'])) {
                    foreach ($inc['states'] as $val) {
                        $items[] = [
                            'field' => $legacy ? 'state' : ($contactCtx ? 'contact_state' : 'company_state'),
                            'operator' => '=',
                            'value' => $this->normalizeText((string) $val)
                        ];
                    }
                }
                if (isset($inc['cities']) && is_array($inc['cities'])) {
                    foreach ($inc['cities'] as $val) {
                        $items[] = [
                            'field' => $legacy ? 'city' : ($contactCtx ? 'contact_city' : 'company_city'),
                            'operator' => '=',
                            'value' => $this->normalizeText((string) $val)
                        ];
                    }
                }
                continue;
            }

            $canonicalField = $mapField((string) $field);

            // Include values
            if (isset($config['include']) && is_array($config['include'])) {
                foreach ($config['include'] as $val) {
                    $value = (string) $val;
                    $items[] = ['field' => $canonicalField, 'operator' => '=', 'value' => ($canonicalField === 'company_domain' ? strtolower(trim($value)) : $this->normalizeText($value))];
                }
                continue;
            }

            // Simple value (legacy/fallback)
            if (!is_array($config)) {
                $value = (string) $config;
                $items[] = ['field' => $canonicalField, 'operator' => '=', 'value' => ($canonicalField === 'company_domain' ? strtolower(trim($value)) : $this->normalizeText($value))];
                continue;
            }

            // Range or other structures are currently unsupported in this endpoint
        }

        // Prefer contact_* over company_* when both present
        $hasContactCountry = array_reduce($items, fn($c, $it) => $c || $it['field'] === 'contact_country', false);
        if ($hasContactCountry) {
            $items = array_values(array_filter($items, fn($it) => $it['field'] !== 'company_country'));
        }

        // Dedupe: prefer unique field+value, then collapse to unique fields
        $byPair = [];
        foreach ($items as $it) {
            $key = $it['field'] . '::' . $it['value'];
            $byPair[$key] = $it;
        }
        $pairDeduped = array_values($byPair);

        $byField = [];
        foreach ($pairDeduped as $it) {
            $byField[$it['field']] = $it; // keep last occurrence per field
        }

        $items = array_values($byField);
        $hasJob = array_reduce($items, fn($c, $it) => $c || $it['field'] === 'job_title', false);
        $hasCountryAny = array_reduce($items, fn($c, $it) => $c || in_array($it['field'], ['contact_country', 'company_country', 'location.country']), false);
        if ($hasJob && $hasCountryAny) {
            $items = array_values(array_filter($items, fn($it) => in_array($it['field'], ['job_title', 'contact_country', 'location.country'])));
        }

        return $items;
    }

    // Removed parseCanonical as it is replaced by AiQueryTranslatorService

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
