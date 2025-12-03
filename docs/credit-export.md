# Strict Credit-Gated Export (Contacts + Companies)

Business rules:
- Email: 1 credit per contact with ≥1 valid email.
- Phone: 4 credits per contact with ≥1 valid phone.
- total_cost = email_count*1 + phone_count*4.
- Export proceeds only if `workspace.credit_balance >= total_cost`; otherwise 402.

Backend endpoints:
- `POST /api/v1/billing/preview-export` returns:
  - `email_count`, `phone_count`, `credits_required`, `balance`, `remaining_after`, plus inclusion counts.
- `POST /api/v1/billing/export` (protected by `ensureCreditsForExport`):
  - Revalidates counts and balance server-side; aborts 402 if insufficient.
  - Deducts credits atomically; records one `CreditTransaction` with `category: export` and counts.
  - Returns `{ url, credits_deducted, remaining_credits, request_id }`.

Error payload on insufficient credits (402):
```json
{
  "error": "INSUFFICIENT_CREDITS",
  "email_count": X,
  "phone_count": Y,
  "credits_needed": Z,
  "balance": B,
  "short_by": Z - B
}
```

CSV columns (contacts & companies with contacts):
- `Full Name, Title, Company, Website, Email, Phone`.

Frontend changes:
- Export dialogs show counts, cost, `balance`, `remaining_after`.
- Disable Export if cost > balance; show “Upgrade Credits”; no sanitized option.

Tests:
- `api/tests/Feature/StrictExportCreditsTest.php` covers preview, strict 402, and successful export with deduction.

