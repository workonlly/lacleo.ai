# STEP 0 — Prepare & Scan

Branch: `fix/full-project-20251203-1330`

## Search Commands

- `rg -n "RecordNormalizer" api app`
- `rg -n "generateFilters" api app`
- `rg -n "AiController" api`
- `rg -n "ExportController" api`
- `rg -n "RevealController" api`
- `rg -n "BillingController" api`
- `rg -n "CompanyController" api`
- `rg -n "SocialResolver|SocialResolverService" api`
- `rg -n "LimitRequestBodySize|RequestTimeout|CsrfGuard" api`
- `rg -n "RateLimiter::for" api`
- `rg -n "throttle:|csrf.guard|limit.request.size|request.timeout" api/routes`

## Consolidated Findings

- RecordNormalizer (12 files):
  - api/app/Services/SearchService.php
  - api/app/Services/RecordNormalizer.php
  - api/app/Services/SocialResolverService.php
  - api/app/Exports/ExportCsvBuilder.php
  - api/app/Http/Middleware/EnsureRevealFieldAvailable.php
  - api/app/Http/Middleware/EnsureCreditsForExport.php
  - api/app/Http/Controllers/Api/v1/RevealController.php
  - api/app/Http/Controllers/Api/v1/ExportController.php
  - api/app/Http/Controllers/Api/v1/CompanyController.php
  - api/tests/Feature/CompanySocialTest.php
  - api/tests/Feature/ExportCsvHeadersTest.php
  - api/tests/Feature/ExportCsvBuilderTest.php

- generateFilters (4 files):
  - app/src/features/ai/slice/apiSlice.ts
  - app/src/features/filters/components/GenerateFiltersPanel.tsx
  - api/app/Http/Controllers/Api/v1/AiController.php
  - api/routes/api.php

- AiController (2 files):
  - api/app/Http/Controllers/Api/v1/AiController.php
  - api/routes/api.php

- ExportController (4 files):
  - docs/code-map.md
  - docs/pr-summary.md
  - api/app/Http/Controllers/Api/v1/ExportController.php
  - api/routes/api.php

- RevealController (4 files):
  - docs/release-notes/fix-reveal-filters-virtualization-ci.md
  - docs/code-map.md
  - api/app/Http/Controllers/Api/v1/RevealController.php
  - api/routes/api.php

- BillingController (3 files):
  - docs/code-map.md
  - api/app/Http/Controllers/Api/v1/BillingController.php
  - api/routes/api.php

- CompanyController (2 files):
  - api/app/Http/Controllers/Api/v1/CompanyController.php
  - api/routes/api.php

- SocialResolver/Service (3 files):
  - api/app/Services/SocialResolverService.php
  - api/app/Http/Controllers/Api/v1/CompanyController.php
  - api/tests/Feature/CompanySocialTest.php

- LimitRequestBodySize / RequestTimeout / CsrfGuard (5 files):
  - api/app/Http/Middleware/LimitRequestBodySize.php
  - api/app/Http/Middleware/RequestTimeout.php
  - api/app/Http/Middleware/CsrfGuard.php
  - api/public/vendor/telescope/app.js
  - api/bootstrap/app.php

- RateLimiter::for (2 files):
  - api/app/Providers/RouteServiceProvider.php
  - accounts/app/Providers/FortifyServiceProvider.php

- Route middleware (throttle/csrf/limit/timeout):
  - api/routes/api.php (multiple occurrences)

## Lint/Test

- Ran: `composer dump-autoload -q && ./vendor/bin/pint --test && ./vendor/bin/pest -q`
- Result: Pint reported 1 style issue, pest was not executed due to pint failure.

Excerpt:

```
Laravel  FAIL  ................................................................................ 140 files, 1 style issue
⨯ app/Http/Controllers/Api/v1/ExportController.php unary_operator_spaces, statement_indentation, not_operator_with_space
```

Per instructions, stopping at STEP 0 due to lint failure. No code changes applied in this step.

