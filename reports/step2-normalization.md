# STEP 2 — Normalization Consistency

## Detection Summary
- Normalizer already centralizes company/contact fields; controllers mostly convert models to arrays before calling.
- ExportController and ExportCsvBuilder already normalize companies and contacts; join uses canonical website/domain.
- RevealController normalizes model arrays before primary field extraction.

## Changes Applied
- api/app/Services/RecordNormalizer.php:
  - getPrimaryEmail($contact) and getPrimaryPhone($contact) now accept model or array and coerce to normalized.

## Unified Diffs

- RecordNormalizer wrappers:
```
*** Begin Patch
*** Update File: api/app/Services/RecordNormalizer.php
@@
-    public static function getPrimaryEmail(array $contact): ?string
+    public static function getPrimaryEmail($contact): ?string
@@
-    public static function getPrimaryPhone(array $contact): ?string
+    public static function getPrimaryPhone($contact): ?string
*** End Patch
```

## Reasoning
- Ensure controllers/tests can safely pass either Eloquent models or arrays to primary value helpers without manual toArray calls.

## Lint/Test Output
- Ran: composer dump-autoload -q && ./vendor/bin/pint --test && ./vendor/bin/pest -q
- Result: PASS (pint clean, full test suite passing).

## Sample API Responses (existing behavior validated)
- Search companies: normalized attributes include company, website, industry, technologies.
- Reveal endpoints: email/phone fields returned with revealed flag and remaining credits.
- Export preview/export: counts consistent; CSV headers canonical.

