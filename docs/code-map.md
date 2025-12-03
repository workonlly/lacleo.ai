# Code Map (LaCleoAI)

## Backend (Laravel)

- Routes: see `php artisan route:list`. Key endpoints:
  - `/api/v1/search/{type}` → `Api\v1\SearchController@search`
  - `/api/v1/filters`, `/api/v1/filter/values` → `Api\v1\SearchController`
  - `/api/v1/logo` → `Api\v1\LogoController@getLogo`
  - Billing
    - `/api/v1/billing/usage` → `Api\v1\BillingController@usage`
    - `/api/v1/billing/preview-export` → `Api\v1\ExportController@preview`
    - `/api/v1/billing/export` → `Api\v1\ExportController@export`
    - `/api/v1/billing/webhook/stripe` → `Api\v1\BillingController@webhookStripe`
  - Reveal
    - `/api/v1/reveal/email` → `Api\v1\RevealController@email`
    - `/api/v1/reveal/phone` → `Api\v1\RevealController@phone`
    - `/api/v1/contacts/{id}/reveal` → `Api\v1\RevealController@revealContact`
    - `/api/v1/companies/{id}/reveal` → `Api\v1\RevealController@revealCompany`

- Elasticsearch
  - Client: `api/app/Elasticsearch/ElasticClient.php`
    - Boot-time delete-blocks, alias ensure, default headers
  - Trait: `api/app/Traits/HasElasticIndex.php`
    - Versioned index creation, alias attach, mapping update across alias-backed indices
  - Mappings: `api/resources/es-mappings/models/*.json`
  - Commands:
    - `elastic:setup` → `api/app/Console/Commands/SetupElasticModels.php`
    - `elastic:snapshot` → `api/app/Console/Commands/ElasticSnapshot.php`
    - `elastic:restore` → `api/app/Console/Commands/ElasticRestore.php`
    - `elastic:alias-swap` → `api/app/Console/Commands/ElasticAliasSwap.php`
    - `elastic:health-check` → `api/app/Console/Commands/ElasticHealthCheck.php`
    - `elastic:scan-destructive` → `api/app/Console/Commands/ElasticStaticScan.php`

## Frontend (React)

- API setup: `app/src/app/redux/apiSlice.ts`
- Search tables:
  - Contacts: `app/src/features/searchTable/contactsTable.tsx`
  - Companies: `app/src/features/searchTable/companiesTable.tsx`
  - Details dialogs: `app/src/features/searchTable/CompanyDetailsDialog.tsx`
- Logo:
  - Hook: `app/src/features/logo/apiSlice.ts` (RTK Query)
  - Utility: `app/src/lib/logo.ts` (extractDomain)
- Billing client:
  - `app/src/features/billing/apiSlice.ts` (usage, preview, export, reveal)

## Destructive Ops Inventory

- Index deletion (guarded)
  - `HasElasticIndex::createIndex(true)` used to call `indices()->delete`; now replaced with alias rollover.
  - `SetupElasticModels --refresh` used to delete; now guarded and non-destructive.
- No `_delete_by_query` / `drop_index` found.
- CI scanner: `elastic:scan-destructive` reports any dangerous patterns outside `dev-tools`.

