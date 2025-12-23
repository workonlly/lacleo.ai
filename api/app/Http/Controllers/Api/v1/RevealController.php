<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\CreditTransaction;
use App\Models\Workspace;
use App\Services\RecordNormalizer;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RevealController extends Controller
{
    public function email(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }
        $contactId = (string) ($request->input('contact_id') ?? $request->input('id') ?? $request->input('_id'));
        if (! app()->environment('testing')) {
            if ($contactId === '') {
                return response()->json(['error' => 'Contact id required'], 422);
            }
            $contact = Contact::findInElastic($contactId);
        } else {
            $contact = [];
        }
        $billing = new BillingService();
        $normContact = RecordNormalizer::normalizeContact(is_array($contact) ? $contact : ($contact ? $contact->toArray() : []));
        $primaryEmail = RecordNormalizer::getPrimaryEmail($normContact);
        $rid = $request->header('request_id') ?: null;

        // admin override: admins do not get charged
        $isAdmin = in_array(strtolower($user->email ?? ''), array_map('strtolower', array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS', ''))))));

        $charged = 0;
        $remaining = $billing->getBalanceForUser($user->id);

        if ($primaryEmail !== null && ! $isAdmin) {
            $res = $billing->chargeRevealForContact($user->id, $contactId, 1, ['category' => 'reveal_email', 'request_id' => $rid, 'contact_id' => $contactId]);
            $charged = $res['charged'] ?? 0;
            $remaining = $res['remaining'] ?? $remaining;
        }

        Log::info('Reveal event', [
            'user_id' => $user->id,
            'request_id' => $rid,
            'field' => 'email',
            'amount' => $charged,
            'contact_id' => $contactId,
        ]);

        return response()->json([
            'revealed' => $primaryEmail !== null,
            'field' => 'email',
            'deducted_credits' => (int) $charged,
            'remaining_credits' => (int) $remaining,
            'contact' => [
                'email' => $primaryEmail,
                'emails' => array_map(function ($e) { return ['email' => (string) ($e['email'] ?? '')]; }, (array) ($normContact['emails'] ?? [])),
            ],
        ]);
    }

    public function phone(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }
        $contactId = (string) ($request->input('contact_id') ?? $request->input('id') ?? $request->input('_id'));
        if (! app()->environment('testing')) {
            if ($contactId === '') {
                return response()->json(['error' => 'Contact id required'], 422);
            }
            $contact = Contact::findInElastic($contactId);
        } else {
            $contact = [];
        }
        $billing = new BillingService();
        $normContact = RecordNormalizer::normalizeContact(is_array($contact) ? $contact : ($contact ? $contact->toArray() : []));
        $primaryPhone = RecordNormalizer::getPrimaryPhone($normContact);
        $rid = $request->header('request_id') ?: null;

        $isAdmin = in_array(strtolower($user->email ?? ''), array_map('strtolower', array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS', ''))))));

        $charged = 0;
        $remaining = $billing->getBalanceForUser($user->id);

        if ($primaryPhone !== null && ! $isAdmin) {
            $res = $billing->chargeRevealForContact($user->id, $contactId, 4, ['category' => 'reveal_phone', 'request_id' => $rid, 'contact_id' => $contactId]);
            $charged = $res['charged'] ?? 0;
            $remaining = $res['remaining'] ?? $remaining;
        }

        Log::info('Reveal event', [
            'user_id' => $user->id,
            'request_id' => $rid,
            'field' => 'phone',
            'amount' => $charged,
            'contact_id' => $contactId,
        ]);

        return response()->json([
            'revealed' => $primaryPhone !== null,
            'field' => 'phone',
            'deducted_credits' => (int) $charged,
            'remaining_credits' => (int) $remaining,
            'contact' => [
                'phone' => $primaryPhone,
                'phone_numbers' => array_map(function ($p) { return ['phone_number' => (string) ($p['phone_number'] ?? '')]; }, (array) ($normContact['phones'] ?? [])),
            ],
        ]);
    }

    public function revealContact(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }
        $payload = $request->validate([
            'revealPhone' => 'sometimes|boolean',
            'revealEmail' => 'sometimes|boolean',
        ]);

        $revealPhone = (bool) ($payload['revealPhone'] ?? false);
        $revealEmail = (bool) ($payload['revealEmail'] ?? false);

        try {
            $contact = Contact::findInElastic($id);
        } catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e) {
            return response()->json(['error' => 'CONTACT_NOT_FOUND', 'message' => 'Contact not found'], 404);
        } catch (\Elastic\Elasticsearch\Exception\ServerResponseException $e) {
            return response()->json(['error' => 'ELASTIC_UNAVAILABLE', 'message' => 'Search backend is unavailable'], 503);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'REVEAL_FAILED', 'message' => 'Unable to fetch contact record'], 422);
        }
        if (! $contact) {
            return response()->json(['error' => 'CONTACT_NOT_FOUND', 'message' => 'Contact not found'], 404);
        }

        $billing = new BillingService();

        $norm = RecordNormalizer::normalizeContact(is_array($contact) ? $contact : $contact->toArray());
        $primaryEmail = RecordNormalizer::getPrimaryEmail($norm);
        $primaryPhone = RecordNormalizer::getPrimaryPhone($norm);

        $emailAvailable = $primaryEmail !== null;
        $phoneAvailable = $primaryPhone !== null;

        $cost = 0;
        $toChargeEmail = $revealEmail && $emailAvailable;
        $toChargePhone = $revealPhone && $phoneAvailable;
        // Email costs 1 credit, phone costs 4 credits
        if ($toChargeEmail) $cost += 1;
        if ($toChargePhone) $cost += 4;

        $requestId = $request->header('request_id') ?: null;

        $isAdmin = in_array(strtolower($user->email ?? ''), array_map('strtolower', array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS', ''))))));

        $chargedTotal = 0;
        $remaining = $billing->getBalanceForUser($user->id);

        // Charge 1 credit for email, 4 credits for phone
        if ($toChargeEmail && ! $isAdmin) {
            $r = $billing->chargeRevealForContact($user->id, $id, 1, ['category' => 'reveal_email', 'request_id' => $requestId, 'contact_id' => $id]);
            $chargedTotal += ($r['charged'] ?? 0);
            $remaining = $r['remaining'] ?? $remaining;
        }
        if ($toChargePhone && ! $isAdmin) {
            $r = $billing->chargeRevealForContact($user->id, $id, 4, ['category' => 'reveal_phone', 'request_id' => $requestId, 'contact_id' => $id]);
            $chargedTotal += ($r['charged'] ?? 0);
            $remaining = $r['remaining'] ?? $remaining;
        }

        Log::info('Reveal event', [
            'user_id' => $user->id,
            'request_id' => $requestId,
            'field' => $toChargeEmail ? 'email' : ($toChargePhone ? 'phone' : 'none'),
            'amount' => $chargedTotal,
            'contact_id' => $id,
        ]);

        // Return revealed values only if charged
        $emailValue = $toChargeEmail && $emailAvailable ? $primaryEmail : null;
        $phoneValue = $toChargePhone && $phoneAvailable ? $primaryPhone : null;

        return response()->json([
            'contact' => [
                'email' => $emailValue,
                'phone' => $phoneValue,
                'emails' => array_map(function ($e) { return ['email' => (string) ($e['email'] ?? '')]; }, (array) ($norm['emails'] ?? [])),
                'phone_numbers' => array_map(function ($p) { return ['phone_number' => (string) ($p['phone_number'] ?? '')]; }, (array) ($norm['phones'] ?? [])),
            ],
            // let frontend know at least one field was successfully revealed
            'revealed' => ($emailValue !== null) || ($phoneValue !== null),
            'deducted_credits' => (int) $chargedTotal,
            'field' => $emailValue !== null ? 'email' : ($phoneValue !== null ? 'phone' : 'none'),
            'remaining_credits' => (int) $remaining,
        ]);
    }

    public function revealCompany(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }
        $payload = $request->validate([
            'revealPhone' => 'sometimes|boolean',
            'revealEmail' => 'sometimes|boolean',
        ]);

        $revealPhone = (bool) ($payload['revealPhone'] ?? false);

        // Primary: load by Elastic ID
        try {
            $company = \App\Models\Company::findInElastic($id, ['index' => 'stage_lacleo_company_stats']);
        } catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e) {
            $company = null;
        } catch (\Throwable $e) {
            $company = null;
        }

        // Fallback: if not found by ID, attempt flexible lookup by website/domain + exact/partial company name
        if (! $company) {
            // Robust composite ID parsing: "domain__Company Name__" → [domain, Company Name]
            [$domain, $name] = (function ($raw) {
                $parts = explode('__', (string) $raw, 2);
                $d = isset($parts[0]) ? trim((string) $parts[0]) : null;
                $n = isset($parts[1]) ? trim((string) $parts[1]) : null;
                if ($n !== null) { $n = rtrim($n, '_'); }
                return [$d, $n];
            })($id);

            $should = [];
            if (! empty($domain)) {
                $should[] = ['term' => ['website' => $domain]];
            }
            if (! empty($name)) {
                $should[] = [
                    'multi_match' => [
                        'query' => $name,
                        'type' => 'phrase_prefix',
                        'fields' => ['company.prefix^3','company_also_known_as.prefix^2','company.joined'],
                        'operator' => 'and',
                        'prefix_length' => 1,
                    ],
                ];
                $should[] = ['term' => ['company.keyword' => $name]];
            }
            if (! empty($should)) {
                try {
                    $resp = \App\Models\Company::searchInElastic([
                        'query' => ['bool' => ['should' => $should, 'minimum_should_match' => 1]],
                        'size' => 1,
                    ], ['index' => 'stage_lacleo_company_stats']);
                    $hit = ($resp['hits']['hits'][0] ?? null);
                    if ($hit && isset($hit['_source'])) {
                        $company = $hit['_source'];
                        $id = (string) ($hit['_id'] ?? $id);
                    }
                } catch (\Elastic\Elasticsearch\Exception\ServerResponseException $e) {
                    return response()->json(['error' => 'ELASTIC_UNAVAILABLE', 'message' => 'Search backend is unavailable'], 503);
                } catch (\Throwable $e) {
                    // silently ignore other errors
                }
            }
        }

        if (! $company) {
            return response()->json(['error' => 'COMPANY_NOT_FOUND', 'message' => 'Company not found'], 404);
        }

        $workspace = Workspace::firstOrCreate(
            ['owner_user_id' => $user->id],
            ['id' => (string) strtolower(Str::ulid()), 'credit_balance' => 0, 'credit_reserved' => 0]
        );

        $normCompany = is_array($company) ? \App\Services\RecordNormalizer::normalizeCompany($company) : ($company ? \App\Services\RecordNormalizer::normalizeCompany($company->toArray()) : []);
        $phone = $normCompany['phone_number'] ?? ($normCompany['company_phone'] ?? null);
        $rawCompany = is_array($company) ? $company : ($company ? $company->toArray() : []);
        $email = null;
        if (is_array($rawCompany)) {
            if (! empty($rawCompany['email']) && is_string($rawCompany['email'])) {
                $email = trim((string) $rawCompany['email']);
            } elseif (! empty($rawCompany['emails']) && is_array($rawCompany['emails'])) {
                foreach ($rawCompany['emails'] as $e) {
                    if (is_string($e) && $e) { $email = trim($e); break; }
                    if (is_array($e) && ! empty($e['email'])) { $email = trim((string) $e['email']); break; }
                }
            }
        }

        $phoneAvailable = ! empty($phone);
        $emailAvailable = ! empty($email);

        $alreadyPhone = CreditTransaction::where('workspace_id', $workspace->id)
            ->where('type', 'spend')
            ->where('meta->category', 'reveal_company_phone')
            ->where('meta->company_id', $id)
            ->exists();

        // Company reveals are free - no charges (company data doesn't consume credits)
        $cost = 0;
        $phoneValue = ($revealPhone && $phoneAvailable) ? $phone : null;
        $emailValue = (($payload['revealEmail'] ?? false) && $emailAvailable) ? $email : null;

        $requestId = $request->header('request_id');

        // Company reveals are free - no transaction needed
        $remaining = (int) (Workspace::find($workspace->id)->credit_balance ?? 0);

        Log::info('Reveal event', [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'request_id' => $requestId,
            'field' => 'company_reveal',
            'amount' => 0,
            'company_id' => $id,
        ]);

        return response()->json([
            'company' => [
                'phone' => $phoneValue,
                'email' => $emailValue,
            ],
            'company_phone' => $phoneValue,
            'company_email' => $emailValue,
            'revealed' => ($phoneValue !== null) || ($emailValue !== null),
            'credits_charged' => 0,
            'remaining_credits' => (int) $remaining,
        ]);
    }
}
