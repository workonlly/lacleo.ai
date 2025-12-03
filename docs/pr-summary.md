# PR Summary: Strict Credit-Gated Export

## Overview
Implements Apollo-style strict credit-gated export for Contacts and Companies. Blocks export when credits are insufficient and removes sanitized export paths.

## Key Changes
- Preview API now returns `balance` with counts and cost.
- Middleware `EnsureCreditsForExport` strictly blocks with 402 + detailed JSON, no sanitized mode.
- ExportController generates full CSV only after atomic deduction; returns `remaining_credits`.
- Frontend export dialogs (contacts/companies) show counts, cost, balance; disable export if insufficient; only CTA is Upgrade.
- New tests validate strict behavior.

## Endpoints
- `POST /api/v1/billing/preview-export`
- `POST /api/v1/billing/export`

## Error Handling
- 402 payload includes `error`, `email_count`, `phone_count`, `credits_needed`, `balance`, `short_by`.

## CSV
- Columns: `Full Name, Title, Company, Website, Email, Phone`.

## Tests
- `api/tests/Feature/StrictExportCreditsTest.php`

## Migration/Config
- None required.

## UI
- Export button disabled on insufficient credits; shows costs and balance; “Upgrade Credits” only.

