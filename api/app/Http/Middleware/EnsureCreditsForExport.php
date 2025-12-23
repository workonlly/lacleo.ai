<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditTransaction;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureCreditsForExport
{
    private const EMAIL_COST = 1; // Work emails cost 1 credit

    private const PHONE_COST = 4; // Phone numbers cost 4 credits

    private const MAX_CONTACTS = 50000;

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }
        // Admins bypass credit enforcement
        $adminEmails = array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS', ''))));
        $isAdmin = in_array(strtolower($user->email ?? ''), array_map('strtolower', $adminEmails), true);

        $workspace = Workspace::firstOrCreate(
            ['owner_user_id' => $user->id],
            ['id' => (string) strtolower(Str::ulid()), 'credit_balance' => 0, 'credit_reserved' => 0]
        );

        if ($isAdmin) {
            // still compute counts and attach attributes for downstream handler
        }

        $payload = $request->validate([
            'type' => 'required|in:contacts,companies',
            'ids' => 'required|array',
            'ids.*' => 'string',
            'sanitize' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:' . self::MAX_CONTACTS,
            'fields' => 'sometimes|array',
            'fields.email' => 'sometimes|boolean',
            'fields.phone' => 'sometimes|boolean',
        ]);

        [$contactsIncluded, $emailCount, $phoneCount, $rows] = $this->computeCounts($payload['type'], $payload['ids'], $request);
        if (!empty($payload['limit'])) {
            $contactsIncluded = min($contactsIncluded, (int) $payload['limit']);
            // Recompute counts for limited subset when not sanitized
            if (empty($payload['sanitize'])) {
                [$contactsIncluded, $emailCount, $phoneCount] = $this->computeCountsLimited($payload['type'], $payload['ids'], (int) $payload['limit']);
                $rows = $payload['type'] === 'contacts' ? $contactsIncluded : max($contactsIncluded, count($payload['ids']));
            } else {
                $emailCount = 0;
                $phoneCount = 0;
                $rows = $payload['type'] === 'contacts' ? $contactsIncluded : max($contactsIncluded, count($payload['ids']));
            }
        }
        if (!empty($payload['sanitize'])) {
            $emailCount = 0;
            $phoneCount = 0;
        }

        // Apply selected field toggles: zero-out counts for deselected fields
        if (!empty($payload['fields']) && is_array($payload['fields'])) {
            $f = $payload['fields'];
            if (array_key_exists('email', $f) && !(bool) $f['email']) {
                $emailCount = 0;
            }
            if (array_key_exists('phone', $f) && !(bool) $f['phone']) {
                $phoneCount = 0;
            }
        }

        if (($payload['type'] === 'contacts' ? $contactsIncluded : $rows) > self::MAX_CONTACTS) {
            return response()->json(['error' => 'Too many records'], 422);
        }

        $creditsRequired = ($emailCount * self::EMAIL_COST) + ($phoneCount * self::PHONE_COST);

        $requestId = $request->header('X-Request-Id') ?: ($request->header('request_id') ?: $request->input('requestId'));
        if ($requestId) {
            $exists = CreditTransaction::where('workspace_id', $workspace->id)
                ->where('type', 'spend')
                ->where('meta->request_id', $requestId)
                ->exists();
            if ($exists) {
                $request->attributes->add([
                    'export_email_count' => $emailCount,
                    'export_phone_count' => $phoneCount,
                    'export_contacts_included' => $contactsIncluded,
                    'export_companies_included' => $payload['type'] === 'companies' ? count($payload['ids']) : 0,
                    'credits_required' => $creditsRequired,
                    'export_rows' => $payload['type'] === 'contacts' ? $contactsIncluded : $rows,
                ]);

                return $next($request);
            }
        }

        if (!$isAdmin && ($workspace->credit_balance ?? 0) < $creditsRequired && $creditsRequired > 0) {
            return response()->json([
                'error' => 'INSUFFICIENT_CREDITS',
                'email_count' => $emailCount,
                'phone_count' => $phoneCount,
                'required' => (int) $creditsRequired,
                'available' => (int) ($workspace->credit_balance ?? 0),
            ], 402);
        } elseif (!$isAdmin && $creditsRequired > 0) {
            DB::transaction(function () use ($workspace, $creditsRequired, $requestId, $payload, $emailCount, $phoneCount, $contactsIncluded) {
                $ws = Workspace::where('id', $workspace->id)->lockForUpdate()->first();
                if (($ws->credit_balance ?? 0) < $creditsRequired) {
                    abort(402, 'Insufficient credits');
                }
                $ws->update(['credit_balance' => $ws->credit_balance - $creditsRequired]);
                CreditTransaction::create([
                    'workspace_id' => $ws->id,
                    'amount' => -$creditsRequired,
                    'type' => 'spend',
                    'meta' => [
                        'category' => 'export',
                        'request_id' => $requestId,
                        'type' => $payload['type'],
                        'email_count' => $emailCount,
                        'phone_count' => $phoneCount,
                        'contacts_included' => $contactsIncluded,
                        'rows' => $payload['type'] === 'contacts' ? $contactsIncluded : max($contactsIncluded, count($payload['ids'])),
                    ],
                ]);
            });
        }

        $cacheKey = "export_params:{$requestId}";
        \Illuminate\Support\Facades\Cache::put($cacheKey, [
            'type' => $payload['type'],
            'ids' => $payload['ids'],
            'fields' => $payload['fields'] ?? [],
            'sanitize' => !empty($payload['sanitize']),
            'limit' => $payload['limit'] ?? self::MAX_CONTACTS,
            'user_id' => $user->id,
        ], 3600);

        $request->attributes->add([
            'export_email_count' => $emailCount,
            'export_phone_count' => $phoneCount,
            'export_contacts_included' => $contactsIncluded,
            'export_companies_included' => $payload['type'] === 'companies' ? count($payload['ids']) : 0,
            'credits_required' => $creditsRequired,
            'export_rows' => $payload['type'] === 'contacts' ? $contactsIncluded : $rows,
            'export_cache_key' => $cacheKey,
        ]);

        return $next($request);
    }

    private function computeCountsLimited(string $type, array $ids, int $limit): array
    {
        if ($type === 'contacts') {
            // If no IDs provided, fetch bulk records
            if (empty($ids)) {
                $result = Contact::elastic()->paginate(1, $limit);
            } else {
                $result = Contact::elastic()
                    ->filter(['terms' => ['_id' => $ids]])
                    ->paginate(1, $limit);
            }
            $data = $result['data'] ?? [];
            $contactsIncluded = count($data);
            $emailCount = 0;
            $phoneCount = 0;
            foreach ($data as $c) {
                $normalized = \App\Services\RecordNormalizer::normalizeContact($c);
                if (!empty($normalized['emails'])) {
                    $emailCount += count($normalized['emails']);
                }
                if (!empty($normalized['phones'])) {
                    $phoneCount++;
                }
            }
            return [$contactsIncluded, $emailCount, $phoneCount];
        }
        
        // For companies: decode IDs, fetch companies directly, no cross-checking, no credits
        $decodedIds = array_map(function ($id) {
            return urldecode(str_replace('+', ' ', $id));
        }, $ids);
        
        $companies = array_map(function ($id) {
            try {
                return Company::findInElastic($id);
            } catch (\Exception $e) {
                return null;
            }
        }, $decodedIds);
        $companies = array_values(array_filter($companies));

        // Company exports are free - no credits charged
        $emailCount = 0;
        $phoneCount = 0;
        $contactsIncluded = 0;

        return [$contactsIncluded, $emailCount, $phoneCount];
    }

    private function computeCounts(string $type, array $ids, Request $request): array
    {
        if (app()->environment('testing') && $request->has('simulate')) {
            $s = $request->input('simulate');

            $contactsIncluded = (int) ($s['contacts_included'] ?? 0);
            $emailCount = (int) ($s['email_count'] ?? 0);
            $phoneCount = (int) ($s['phone_count'] ?? 0);
            $rows = $type === 'contacts' ? $contactsIncluded : max($contactsIncluded, count($ids));

            return [$contactsIncluded, $emailCount, $phoneCount, $rows];
        }
        if ($type === 'contacts') {
            // If no IDs provided, fetch bulk records without filter
            if (empty($ids)) {
                $base = Contact::elastic();
            } else {
                $base = Contact::elastic()->filter(['terms' => ['_id' => $ids]]);
            }
            $page = 1;
            $per = 1000;
            $result = $base->paginate($page, $per);
            $contactsIncluded = $result['total'] ?? count($result['data'] ?? []);
            $emailCount = 0;
            $phoneCount = 0;
            $last = $result['last_page'] ?? 1;
            while (true) {
                foreach (($result['data'] ?? []) as $c) {
                    $norm = \App\Services\RecordNormalizer::normalizeContact($c);
                    if (!empty($norm['emails'])) {
                        $emailCount += count($norm['emails']);
                    }
                    if (!empty($norm['phones'])) {
                        $phoneCount++;
                    }
                }
                if ($page >= $last) {
                    break;
                }
                $page++;
                $result = $base->paginate($page, $per);
            }
            return [$contactsIncluded, $emailCount, $phoneCount, $contactsIncluded];
        }

        // For companies: don't cross-check contacts, don't charge any credits
        // Just return counts for metadata - all credits are 0
        
        // If no IDs provided, return 0 counts (bulk export will be handled by controller)
        if (empty($ids)) {
            return [0, 0, 0, 0];
        }
        
        $decodedIds = array_map(function ($id) {
            return urldecode(str_replace('+', ' ', $id));
        }, $ids);
        
        $companies = array_map(function ($id) {
            try {
                return Company::findInElastic($id);
            } catch (\Exception $e) {
                return null;
            }
        }, $decodedIds);
        $companies = array_values(array_filter($companies));

        // Company exports are free - no credits charged
        $emailCount = 0;
        $phoneCount = 0;
        $contactsIncluded = 0;
        $rows = count($companies);

        return [$contactsIncluded, $emailCount, $phoneCount, $rows];
    }
}
