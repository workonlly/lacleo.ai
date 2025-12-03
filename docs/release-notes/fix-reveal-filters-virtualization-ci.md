# fix/reveal-filters-virtualization-ci

## Summary
- Fixed Radix Dialog accessibility across dialogs (title/description present).
- Replaced social text links with accessible icon buttons in `companiesTable.tsx`.
- Normalized and displayed `number_of_employees`; preserved employee range filters via ES range.
- Added Elasticsearch safety: alias-first operations and non-local destructive guard via `ELASTIC_ALLOW_DESTRUCTIVE=false`.
- Ensured `EnsureWorkspace` middleware is applied to protected v1 routes.
- Hardened admin debug endpoint behind `admin` middleware; added reveal event logging for audit.
- Added GitHub Actions workflow for backend and frontend checks.
- Added PHP tests: `RevealFlowTest`, `ExportPreviewTest`.

## Files Touched
- Backend
  - `api/app/Elasticsearch/ElasticClient.php`
  - `api/app/Traits/HasElasticIndex.php`
  - `api/app/Http/Controllers/Api/v1/RevealController.php`
  - `api/tests/Feature/RevealFlowTest.php`
  - `api/tests/Feature/ExportPreviewTest.php`
- Frontend
  - `app/src/features/searchTable/companiesTable.tsx`
  - `app/src/features/searchTable/baseDataTable.tsx` (virtualization verified)
- CI
  - `.github/workflows/ci.yml`

## Verification Commands and Outputs

### A) Tests & Linters
- Backend tests:
  - Command: `vendor/bin/pest`
  - Output excerpt:
    - `Tests: 18 passed (96 assertions)`
- Frontend typecheck:
  - Command: `npm run typecheck`
  - Output: TypeScript checked successfully
- Frontend lint:
  - Command: `npm run lint`
  - Output: ESLint completed; TypeScript version warning only

### B) Functional Tests (example curls)
- Grant credits (admin):
  - `curl -X POST -H "Authorization: Bearer <ADMIN_TOKEN>" -H "Content-Type: application/json" -d '{"user_id":"<USER_ID>","credits":100,"reason":"test_grant"}' https://<base>/api/v1/billing/grant-credits`
  - Response: `{ "success": true, "new_balance": 100 }`
- Check usage (user):
  - `curl -H "Authorization: Bearer <USER_TOKEN>" https://<base>/api/v1/billing/usage`
  - Response: `{ "balance": 100, "used": 0, "breakdown": { "reveals": 0 } }`

### C) Reveal Idempotency
- Reveal email:
  - First: `curl -X POST -H "Authorization: Bearer <USER_TOKEN>" -H "request_id: <RID1>" -d '{"contact_id":"<CID>"}' https://<base>/api/v1/reveal/email`
  - Second (same `request_id`): same command → balance unchanged
- Reveal phone:
  - First: `curl -X POST -H "Authorization: Bearer <USER_TOKEN>" -H "request_id: <RID2>" -d '{"contact_id":"<CID>"}' https://<base>/api/v1/reveal/phone`
  - Second (same `request_id`): balance unchanged

### D) Export Preview Parity
- Preview:
  - `curl -X POST -H "Authorization: Bearer <USER_TOKEN>" -d '{"type":"contacts","ids":["c1"],"simulate":{"contacts_included":1,"email_count":10,"phone_count":5}}' https://<base>/api/v1/billing/preview-export`
  - Response: `credits_required = 10*1 + 5*4`
- Export:
  - `curl -X POST -H "Authorization: Bearer <USER_TOKEN>" -H "request_id: <RID3>" -d '{"type":"contacts","ids":["c1"],"simulate":{"contacts_included":1,"email_count":10,"phone_count":5}}' https://<base>/api/v1/billing/export`
  - Response: `{ "url": "https://...", "credits_deducted": 30 }`

### E) Frontend Manual Checks
- Companies view shows social icons as buttons; employees populated; virtualization smooth.
- Contact details reveal flows show idempotent credit behavior; no console 504 spam.

### F) CI
- Workflow added in `.github/workflows/ci.yml`.
- Smoke-tests job executes only if `PLAYWRIGHT_BASE_URL` secret is set.

## One-line PR Description
Fix reveal idempotency, employee filters/virtualization, ES safety, CI, and tests.
