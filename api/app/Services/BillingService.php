<?php

namespace App\Services;

use App\Models\Workspace;
use App\Models\CreditTransaction;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function getBalanceForUser($userId): int
    {
        $ws = Workspace::firstOrCreate(
            ['owner_user_id' => $userId],
            ['id' => (string) strtolower(Str::ulid()), 'credit_balance' => 0, 'credit_reserved' => 0]
        );

        return (int) ($ws->credit_balance ?? 0);
    }

    /**
     * Charge reveal for contact. Returns amount charged (0 if free or already charged) and remaining balance.
     */
    public function chargeRevealForContact(string $userId, string $contactId, int $cost, array $meta = []): array
    {
        $ws = Workspace::firstOrCreate(
            ['owner_user_id' => $userId],
            ['id' => (string) strtolower(Str::ulid()), 'credit_balance' => 0, 'credit_reserved' => 0]
        );

        $charged = 0;
        $requestId = $meta['request_id'] ?? null;

        // Idempotency: if request_id present and a spend tx exists, return 0
        if ($requestId) {
            $existing = CreditTransaction::where('workspace_id', $ws->id)
                ->where('type', 'spend')
                ->where('meta->request_id', $requestId)
                ->first();
            if ($existing) {
                return ['charged' => 0, 'remaining' => (int) $ws->credit_balance];
            }
        }

        // Per-contact idempotency: if already charged for contact and category present in meta, skip
        if (!empty($meta['category']) && !empty($meta['contact_id'])) {
            $exists = CreditTransaction::where('workspace_id', $ws->id)
                ->where('type', 'spend')
                ->where('meta->category', $meta['category'])
                ->where('meta->contact_id', $meta['contact_id'])
                ->exists();
            if ($exists) {
                return ['charged' => 0, 'remaining' => (int) $ws->credit_balance];
            }
        }

        if ($cost <= 0) {
            return ['charged' => 0, 'remaining' => (int) $ws->credit_balance];
        }

        DB::transaction(function () use (&$ws, &$charged, $cost, $meta) {
            $w = Workspace::where('id', $ws->id)->lockForUpdate()->first();
            if (($w->credit_balance ?? 0) < $cost) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'error' => 'INSUFFICIENT_CREDITS',
                    'balance' => (int) ($w->credit_balance ?? 0),
                    'needed' => (int) $cost,
                    'short_by' => max(0, (int) $cost - (int) ($w->credit_balance ?? 0)),
                ], 402));
            }
            $w->update(['credit_balance' => $w->credit_balance - $cost]);
            $charged = $cost;

            CreditTransaction::create([
                'id' => (string) strtolower(Str::ulid()),
                'workspace_id' => $w->id,
                'amount' => -$cost,
                'type' => 'spend',
                'meta' => $meta,
            ]);
            $ws = $w;
        });

        return ['charged' => $charged, 'remaining' => (int) $ws->credit_balance];
    }

    public function grantCreditsToUser(string $adminId, string $targetUserId, int $credits, ?string $reason = null): int
    {
        $targetWorkspace = Workspace::firstOrCreate(
            ['owner_user_id' => $targetUserId],
            ['id' => (string) strtolower(Str::ulid()), 'credit_balance' => 0, 'credit_reserved' => 0]
        );

        DB::transaction(function () use ($targetWorkspace, $adminId, $credits, $reason) {
            $ws = Workspace::where('id', $targetWorkspace->id)->lockForUpdate()->first();
            $ws->increment('credit_balance', (int) $credits);

            \App\Models\CreditGrant::create([
                'id' => (string) strtolower(Str::ulid()),
                'user_id' => $targetWorkspace->owner_user_id,
                'granted_by' => $adminId,
                'credits' => (int) $credits,
                'reason' => $reason ?? 'free_grant',
            ]);

            CreditTransaction::create([
                'id' => (string) strtolower(Str::ulid()),
                'workspace_id' => $ws->id,
                'amount' => (int) $credits,
                'type' => 'adjustment',
                'meta' => ['reason' => $reason ?? 'free_grant', 'granted_by' => $adminId],
            ]);
        });

        $fresh = Workspace::find($targetWorkspace->id);
        return (int) ($fresh->credit_balance ?? 0);
    }
}
