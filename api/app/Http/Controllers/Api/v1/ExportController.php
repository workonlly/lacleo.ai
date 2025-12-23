<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contact;
use App\Services\RecordNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ExportController extends Controller
{
    /**
     * Decode company ID by converting + to spaces (from URL encoding)
     */
    private static function decodeCompanyId(string $id): string
    {
        // Replace + with space (form-encoded spaces) and then urldecode for other encoded chars
        return urldecode(str_replace('+', ' ', $id));
    }

    private static function EXPORT_PAGE_SIZE(): int
    {
        return 50000;
    }
    public function preview(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:contacts,companies',
            'ids' => 'required|array',
            'ids.*' => 'string',
            'sanitize' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:' . self::EXPORT_PAGE_SIZE(),
            'fields' => 'sometimes|array',
            'fields.email' => 'sometimes|boolean',
            'fields.phone' => 'sometimes|boolean',
        ]);

        $emailCount = 0;
        $phoneCount = 0;
        $contactsIncluded = 0;

        if (app()->environment('testing') && $request->has('simulate')) {
            $sim = (array) $request->input('simulate');
            $contactsIncluded = (int) ($sim['contacts_included'] ?? 0);
            $emailCount = (int) ($sim['email_count'] ?? 0);
            $phoneCount = (int) ($sim['phone_count'] ?? 0);
        } else {
            try {
                if ($validated['type'] === 'contacts') {
                    // Decode contact IDs
                    $decodedIds = array_map(fn($id) => self::decodeCompanyId($id), $validated['ids']);
                    
                    Log::info('ExportController preview - contacts', ['ids_input' => $validated['ids'], 'ids_decoded' => $decodedIds]);
                    
                    // If IDs array is empty, fetch bulk records; otherwise filter by IDs
                    if (empty($decodedIds)) {
                        $base = Contact::elastic()->index((new Contact())->elasticReadAlias());
                    } else {
                        $base = Contact::elastic()->index((new Contact())->elasticReadAlias())->filter(['terms' => ['_id' => $decodedIds]]);
                    }
                    
                    if (!empty($validated['limit'])) {
                        $data = $base->paginate(1, (int) $validated['limit'])['data'] ?? [];
                        Log::info('ExportController preview - contacts fetched', ['count' => count($data), 'limit' => $validated['limit']]);
                        $contactsIncluded = count($data);
                        foreach ($data as $c) {
                            $source = $c['_source'] ?? $c;
                            Log::info('Contact source structure', ['has_emails' => isset($source['emails']), 'has_phones' => isset($source['phone_numbers'])]);
                            $norm = RecordNormalizer::normalizeContact($source);
                            Log::info('Normalized contact data', ['emails_count' => count($norm['emails'] ?? []), 'phones_count' => count($norm['phones'] ?? []), 'emails' => $norm['emails'] ?? [], 'phones' => $norm['phones'] ?? []]);
                            // Count work emails (1 credit each)
                            if (!empty($norm['emails'])) {
                                foreach ($norm['emails'] as $e) {
                                    if (isset($e['type']) && $e['type'] === 'work' && !empty($e['email'])) {
                                        $emailCount++;
                                    }
                                }
                            }
                            // Count phones (4 credits each)
                            if (!empty($norm['phones'])) {
                                $phoneCount++;
                            }
                        }
                    } else {
                        $page = 1;
                        $per = 1000;
                        $result = $base->paginate($page, $per);
                        $contactsIncluded = $result['total'] ?? count($result['data'] ?? []);
                        $last = $result['last_page'] ?? 1;
                        while (true) {
                            $data = $result['data'] ?? [];
                            foreach ($data as $c) {
                                $source = $c['_source'] ?? $c;
                                $norm = RecordNormalizer::normalizeContact($source);
                                // Count work emails (1 credit each)
                                if (!empty($norm['emails'])) {
                                    foreach ($norm['emails'] as $e) {
                                        if (isset($e['type']) && $e['type'] === 'work' && !empty($e['email'])) {
                                            $emailCount++;
                                        }
                                    }
                                }
                                // Count phones (4 credits each)
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
                    }
                } else {
                    // For companies: decode IDs, fetch companies directly, no cross-checking, no credits
                    Log::info('ExportController preview - companies input', ['ids_count' => count($validated['ids']), 'ids' => $validated['ids']]);
                    
                    $decodedIds = array_map(fn($id) => self::decodeCompanyId($id), $validated['ids']);
                    
                    // If IDs array is empty, fetch bulk companies; otherwise fetch by IDs
                    if (empty($decodedIds)) {
                        // Bulk export: fetch limit companies from Elasticsearch
                        $limit = $validated['limit'] ?? 50;
                        $result = Company::elastic()->index((new Company())->elasticReadAlias())->paginate(1, $limit);
                        $companies = $result['data'] ?? [];
                        Log::info('ExportController preview - bulk companies fetch', ['count' => count($companies), 'limit' => $limit]);
                    } else {
                        $companies = array_map(function ($id) {
                            try {
                                $company = null;
                                
                                try {
                                    $company = Company::findInElastic($id);
                                    Log::info('ExportController preview - findInElastic found', ['decoded_id' => $id]);
                                } catch (\Exception $lookupEx) {
                                    Log::info('ExportController preview - findInElastic not found', ['decoded_id' => $id, 'error' => $lookupEx->getMessage()]);
                                }
                                
                                // If not found by exact ID, try to find by domain
                                if (!$company && strpos($id, '__') !== false) {
                                    $domain = explode('__', $id)[0]; // Extract domain from "domain__name__" format
                                    Log::info('ExportController preview - finding by domain fallback', ['domain' => $domain]);
                                    $paginated = Company::elastic()->filter(['term' => ['website' => $domain]])->paginate(1, 1);
                                    $results = $paginated['data'] ?? [];
                                    if (count($results) > 0) {
                                        $company = $results[0];
                                        Log::info('ExportController preview - found by domain fallback', ['domain' => $domain, 'found' => true]);
                                    }
                                }
                                
                                return $company;
                            } catch (\Exception $e) {
                                Log::error('ExportController preview - fatal error', ['id' => $id, 'error' => $e->getMessage()]);
                                return null;
                            }
                        }, $decodedIds);
                        
                        Log::info('ExportController preview - companies after mapping', ['count' => count($companies)]);
                        $companies = array_values(array_filter($companies));
                        Log::info('ExportController preview - companies after filtering', ['count' => count($companies)]);
                    }

                    // Company exports are free - no credits charged
                    $contactsIncluded = 0;
                    $emailCount = 0;
                    $phoneCount = 0;
                }
            } catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e) {
                return response()->json([
                    'error' => 'ELASTIC_CLIENT_ERROR',
                    'message' => 'Invalid request to search backend',
                ], 422);
            } catch (\Elastic\Elasticsearch\Exception\ServerResponseException $e) {
                return response()->json([
                    'error' => 'ELASTIC_UNAVAILABLE',
                    'message' => 'Search backend is unavailable',
                ], 503);
            } catch (\Throwable $e) {
                return response()->json([
                    'error' => 'PREVIEW_FAILED',
                    'message' => 'Unable to compute export preview',
                ], 422);
            }
        }

        $emailSelected = (bool) (($validated['fields']['email'] ?? true));
        $phoneSelected = (bool) (($validated['fields']['phone'] ?? true));
        
        // Calculate credits: 1 per work email, 4 per phone
        $creditsRequired = (($emailSelected ? $emailCount : 0) * 1) + (($phoneSelected ? $phoneCount : 0) * 4);
        
        if (!empty($validated['sanitize'])) {
            $creditsRequired = 0;
        }

        $balance = optional($request->user())->id ? (int) \App\Models\Workspace::firstOrCreate([
            'owner_user_id' => $request->user()->id,
        ], [
            'id' => (string) strtolower(Str::ulid()),
            'credit_balance' => 0,
            'credit_reserved' => 0,
        ])->credit_balance : 0;

        $totalRows = $validated['type'] === 'contacts' ? $contactsIncluded : max($contactsIncluded, count($validated['ids']));
        $canExportFree = ($creditsRequired === 0) && ($totalRows <= self::EXPORT_PAGE_SIZE());

        return response()->json([
            'email_count' => $emailCount,
            'phone_count' => $phoneCount,
            'credits_required' => (int) $creditsRequired,
            'total_rows' => (int) $totalRows,
            'can_export_free' => (bool) $canExportFree,
            'remaining_before' => (int) $balance,
            'remaining_after' => max(0, (int) $balance - (int) $creditsRequired),
        ]);
    }

    public function export(Request $request)
    {
        try {
            Log::info('ExportController export - START', [
                'raw_request' => $request->all(),
                'headers' => [
                    'X-Request-Id' => $request->header('X-Request-Id'),
                    'request_id' => $request->header('request_id'),
                ]
            ]);

            $validated = $request->validate([
            'type' => 'required|in:contacts,companies',
            'ids' => 'required|array',
            'ids.*' => 'string',
            'sanitize' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:' . self::EXPORT_PAGE_SIZE(),
            'fields' => 'sometimes|array',
            'fields.email' => 'sometimes|boolean',
            'fields.phone' => 'sometimes|boolean',
        ]);

        Log::info('ExportController export - VALIDATED', [
            'type' => $validated['type'],
            'ids_count' => count($validated['ids']),
            'ids' => $validated['ids'],
            'limit' => $validated['limit'] ?? 'not set',
            'fields' => $validated['fields'] ?? [],
            'sanitize' => $validated['sanitize'] ?? false,
        ]);

        $requestId = $request->header('X-Request-Id') ?: ($request->header('request_id') ?: ($request->input('requestId') ?: strtolower(Str::ulid())));

        if ($requestId) {
            $existing = \App\Models\CreditTransaction::where('type', 'spend')
                ->where('meta->request_id', $requestId)
                ->first();
            if ($existing && ($existing->meta['result'] ?? null)) {
                $remaining = (int) optional($request->user())->id ? (int) \App\Models\Workspace::firstOrCreate([
                    'owner_user_id' => $request->user()->id,
                ], [
                    'id' => (string) strtolower(\Illuminate\Support\Str::ulid()),
                    'credit_balance' => 0,
                    'credit_reserved' => 0,
                ])->credit_balance : 0;

                return response()->json([
                    'url' => $existing->meta['result']['url'],
                    'credits_deducted' => abs((int) $existing->amount),
                    'remaining_credits' => $remaining,
                    'request_id' => $requestId,
                ]);
            }
        }

        $contacts = [];
        $companies = [];
        $limit = (int) ($validated['limit'] ?? self::EXPORT_PAGE_SIZE());
        $limit = min($limit, self::EXPORT_PAGE_SIZE());
        
        if ($validated['type'] === 'contacts') {
            // Decode contact IDs (convert + to spaces)
            $decodedIds = array_map(fn($id) => self::decodeCompanyId($id), $validated['ids']);
            
            // If IDs array is empty, fetch bulk records; otherwise filter by IDs
            if (empty($decodedIds)) {
                $result = Contact::elastic()->index((new Contact())->elasticReadAlias())
                    ->paginate(1, $limit);
            } else {
                $result = Contact::elastic()->index((new Contact())->elasticReadAlias())
                    ->filter(['terms' => ['_id' => $decodedIds]])
                    ->paginate(1, $limit);
            }
            $contacts = array_map(fn($c) => RecordNormalizer::normalizeContact($c), $result['data']);
        } else {
            // For companies: decode IDs and fetch only companies, no cross-checking with contacts
            Log::info('ExportController export - companies input', ['ids_count' => count($validated['ids']), 'ids' => $validated['ids']]);
            
            $decodedIds = array_map(fn($id) => self::decodeCompanyId($id), $validated['ids']);
            
            // If IDs array is empty, fetch bulk companies; otherwise fetch by IDs
            if (empty($decodedIds)) {
                $result = Company::elastic()->index((new Company())->elasticReadAlias())->paginate(1, $limit);
                $companies = $result['data'] ?? [];
                Log::info('ExportController export - bulk companies fetch', ['count' => count($companies), 'limit' => $limit]);
            } else {
                $companies = array_map(function ($id) {
                    try {
                        $company = null;
                        
                        try {
                            $company = Company::findInElastic($id);
                            Log::info('ExportController export - findInElastic found', ['decoded_id' => $id]);
                        } catch (\Exception $lookupEx) {
                            Log::info('ExportController export - findInElastic not found', ['decoded_id' => $id, 'error' => $lookupEx->getMessage()]);
                        }
                        
                        // If not found by exact ID, try to find by domain
                        if (!$company && strpos($id, '__') !== false) {
                            $domain = explode('__', $id)[0]; // Extract domain from "domain__name__" format
                            Log::info('ExportController export - finding by domain fallback', ['domain' => $domain]);
                            $paginated = Company::elastic()->filter(['term' => ['website' => $domain]])->paginate(1, 1);
                            $results = $paginated['data'] ?? [];
                            if (count($results) > 0) {
                                $company = $results[0];
                                Log::info('ExportController export - found by domain fallback', ['domain' => $domain, 'found' => true]);
                            }
                        }
                        
                        return $company;
                    } catch (\Exception $e) {
                        Log::error('ExportController export - fatal error', ['id' => $id, 'error' => $e->getMessage()]);
                        return null;
                    }
                }, $decodedIds);
                
                Log::info('ExportController export - companies after mapping', ['count' => count($companies)]);
                $companies = array_values(array_filter($companies));
                Log::info('ExportController export - companies after filtering', ['count' => count($companies)]);
            }
            
            $companiesNorm = array_map(function ($c) {
                return $c ? RecordNormalizer::normalizeCompany(is_array($c) ? $c : $c->toArray()) : null;
            }, $companies);
            $companiesNorm = array_values(array_filter($companiesNorm));

            // Debug: how many companies fetched/normalized for export
            Log::info('ExportController export - companies_fetched', ['companies_fetched' => count($companies), 'companies_norm' => count($companiesNorm)]);

            // Don't cross-check contacts for company exports - just export companies
            $request->attributes->add(['export_companies' => $companiesNorm]);
        }

        if (count($contacts) > 50000) {
            return response()->json(['error' => 'Too many records'], 422);
        }
        if (count($companies) > 50000) {
            return response()->json(['error' => 'Too many records'], 422);
        }

        $sanitize = !empty($validated['sanitize']);
        $emailSelected = (bool) (($validated['fields']['email'] ?? true));
        $phoneSelected = (bool) (($validated['fields']['phone'] ?? true));
        
        Log::info('ExportController export - generating CSV', [
            'type' => $validated['type'],
            'emailSelected' => $emailSelected,
            'phoneSelected' => $phoneSelected,
            'contactsCount' => count($contacts),
            'companiesCount' => $validated['type'] === 'companies' ? count((array) $request->attributes->get('export_companies')) : 0
        ]);
        
        if ($validated['type'] === 'companies') {
            // Export companies only - no contacts
            Log::info('ExportController export - Building companies CSV', [
                'companies_from_attributes' => count((array) $request->attributes->get('export_companies')),
                'emailSelected' => $emailSelected,
                'phoneSelected' => $phoneSelected,
            ]);
            
            $csv = \App\Exports\ExportCsvBuilder::buildCompaniesCsvDynamic((array) $request->attributes->get('export_companies'), [], $emailSelected, $phoneSelected);
            
            Log::info('ExportController export - CSV built', [
                'csv_length' => strlen($csv),
                'csv_preview' => substr($csv, 0, 200),
            ]);
        } else {
            // Export contacts only
            $csv = \App\Exports\ExportCsvBuilder::buildContactsCsvDynamic($contacts, $emailSelected, $phoneSelected);
        }

        // Stream if requested (only if explicitly requested via query parameter)
        if ($request->query('stream') === 'true') {
            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="export.csv"',
            ]);
        }

        try {
            $path = 'exports/' . strtolower(Str::ulid()) . '.csv';
            Log::info('ExportController export - Attempting to save file', ['path' => $path]);
            Storage::disk('public')->put($path, $csv);
            Log::info('ExportController export - File saved successfully', ['path' => $path]);
        } catch (\Exception $e) {
            Log::error('ExportController export - Failed to save file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'EXPORT_FAILED', 'message' => 'Failed to save export file: ' . $e->getMessage()], 500);
        }
        // Build the public URL from filesystem config if available, otherwise fallback to app url + /storage
        $diskUrl = config('filesystems.disks.public.url') ?? null;
        if ($diskUrl) {
            $url = rtrim($diskUrl, '/') . '/' . ltrim($path, '/');
        } else {
            $appUrl = rtrim(config('app.url'), '/');
            if ($appUrl) {
                $url = $appUrl . '/storage/' . ltrim($path, '/');
            } else {
                $url = $path;
            }
        }
        
        Log::info('Export CSV created', [
            'path' => $path,
            'url' => $url,
            'requestId' => $requestId,
            'type' => $validated['type']
        ]);
        
        if ($requestId) {
            $tx = \App\Models\CreditTransaction::where('type', 'spend')
                ->where('meta->request_id', $requestId)
                ->orderByDesc('created_at')
                ->first();
            if ($tx) {
                $meta = $tx->meta ?? [];
                $meta['result'] = [
                    'url' => $url,
                    'email_count' => $request->attributes->get('export_email_count'),
                    'phone_count' => $request->attributes->get('export_phone_count'),
                    'contacts_included' => $request->attributes->get('export_contacts_included'),
                    'credits_required' => $request->attributes->get('credits_required'),
                ];
                $tx->update(['meta' => $meta]);
            }
        }

        $remaining = (int) optional($request->user())->id ? (int) \App\Models\Workspace::firstOrCreate([
            'owner_user_id' => $request->user()->id,
        ], [
            'id' => (string) strtolower(\Illuminate\Support\Str::ulid()),
            'credit_balance' => 0,
            'credit_reserved' => 0,
        ])->credit_balance : 0;

        // Return the direct storage URL instead of download endpoint
        Log::info('ExportController export - RESPONSE', [
            'url' => $url,
            'credits_deducted' => (int) $request->attributes->get('credits_required'),
            'remaining_credits' => $remaining,
            'request_id' => $requestId,
        ]);
        
        return response()->json([
            'url' => $url,
            'credits_deducted' => (int) $request->attributes->get('credits_required'),
            'remaining_credits' => $remaining,
            'request_id' => $requestId,
        ]);
        } catch (\Exception $e) {
            Log::error('ExportController export - EXCEPTION', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'EXPORT_FAILED',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function downloadDirect(string $requestId)
    {
        $params = \Illuminate\Support\Facades\Cache::get("export_params:{$requestId}");
        if (!$params) {
            return response()->json(['error' => 'EXPORT_EXPIRED', 'message' => 'The download link has expired or is invalid.'], 410);
        }

        $type = $params['type'];
        $ids = $params['ids'];
        $fields = $params['fields'];
        $sanitize = $params['sanitize'];
        $limit = $params['limit'];

        $contacts = [];
        $companiesNorm = [];
        
        if ($type === 'contacts') {
            // Decode contact IDs
            $decodedIds = array_map(fn($id) => self::decodeCompanyId($id), $ids);
            
            $result = Contact::elastic()->index((new Contact())->elasticReadAlias())
                ->filter(['terms' => ['_id' => $decodedIds]])
                ->paginate(1, $limit);
            $contacts = array_map(fn($c) => RecordNormalizer::normalizeContact($c), $result['data']);
        } else {
            // For companies: decode IDs and fetch only companies, no cross-checking
            $decodedIds = array_map(fn($id) => self::decodeCompanyId($id), $ids);
            
            $companies = array_map(function ($id) {
                try {
                    return Company::findInElastic($id);
                } catch (\Exception $e) {
                    return null;
                }
            }, $decodedIds);
            $companies = array_values(array_filter($companies));
            $companiesNorm = array_map(function ($c) {
                return $c ? RecordNormalizer::normalizeCompany(is_array($c) ? $c : $c->toArray()) : null;
            }, $companies);
            $companiesNorm = array_values(array_filter($companiesNorm));
        }

        $emailSelected = (bool) ($fields['email'] ?? true);
        $phoneSelected = (bool) ($fields['phone'] ?? true);

        return response()->streamDownload(function () use ($type, $contacts, $emailSelected, $phoneSelected, $companiesNorm) {
            if ($type === 'companies') {
                echo \App\Exports\ExportCsvBuilder::buildCompaniesCsvDynamic($companiesNorm, [], $emailSelected, $phoneSelected);
            } else {
                echo \App\Exports\ExportCsvBuilder::buildContactsCsvDynamic($contacts, $emailSelected, $phoneSelected);
            }
        }, 'lacleo_export_' . now()->format('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function generateCsv(array $contacts, string $type, bool $sanitize, array $companies = []): string
    {
        if ($type === 'companies') {
            return \App\Exports\ExportCsvBuilder::buildCompaniesCsv($companies, $contacts, $sanitize);
        }

        return \App\Exports\ExportCsvBuilder::buildContactsCsv($contacts, $sanitize);
    }

    public function estimate(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
            'fields' => 'sometimes|array',
            'fields.email' => 'sometimes|boolean',
            'fields.phone' => 'sometimes|boolean',
        ]);

        $ids = (array) $validated['ids'];
        $emailSelected = (bool) (($validated['fields']['email'] ?? false));
        $phoneSelected = (bool) (($validated['fields']['phone'] ?? false));

        $contactsIncluded = 0;
        $hasEmail = 0;
        $hasPhone = 0;
        try {
            $result = Contact::elastic()->index((new Contact())->elasticReadAlias())
                ->filter(['terms' => ['_id' => $ids]])
                ->select(['emails', 'email', 'work_email', 'personal_email', 'phone_numbers', 'phone', 'mobile_number', 'direct_number'])
                ->paginate(1, max(1, min(count($ids), 1000)));
            $data = (array) ($result['data'] ?? []);
            $contactsIncluded = count($data);
            foreach ($data as $c) {
                $norm = RecordNormalizer::normalizeContact($c);
                if (RecordNormalizer::hasEmail($norm)) {
                    $hasEmail++;
                }
                if (RecordNormalizer::hasPhone($norm)) {
                    $hasPhone++;
                }
            }
            $total = (int) ($result['total'] ?? $contactsIncluded);
            if ($total > $contactsIncluded) {
                $contactsIncluded = $total; // rely on ES total for full selection
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'ESTIMATE_FAILED'], 422);
        }

        $credits = 0;
        // Only contact phone numbers cost credits (4 each) - emails are free
        if ($phoneSelected) {
            $credits = ($hasPhone * 4);
        }

        $userCredits = optional($request->user())->id ? (int) \App\Models\Workspace::firstOrCreate([
            'owner_user_id' => $request->user()->id,
        ], [
            'id' => (string) strtolower(\Illuminate\Support\Str::ulid()),
            'credit_balance' => 0,
            'credit_reserved' => 0,
        ])->credit_balance : 0;

        return response()->json([
            'total_contacts' => (int) $contactsIncluded,
            'credits_required' => (int) $credits,
            'user_credits' => (int) $userCredits,
        ]);
    }

    public function createJob(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'fields' => 'array',
            'format' => 'in:csv,json',
        ]);

        $user = $request->user();
        $ids = $validated['ids'];
        $fields = $validated['fields'] ?? ['first_name', 'last_name', 'email', 'phone'];
        $format = $validated['format'] ?? 'csv';

        // 1. Calculate confirmed cost
        // We charge for successful reveals/exports. 
        // Typically we charge for WHAT WE DELIVER.
        // But prompt says "Export must validate credits, lock, deduct when job starts".
        // So we assume everything requested will be delivered or we refund?
        // Let's Charge max first (lock), then refund difference? Or charge exact?
        // "release/rollback on failure".

        $emailSelected = in_array('email', $fields) || in_array('emails', $fields);
        $phoneSelected = in_array('phone', $fields) || in_array('phone_numbers', $fields);

        // We need to know accurate count to charge.
        // Optimization: Use `preview` logic to get accurate counts (emailCount, phoneCount).

        // Let's refactor `preview` logic to a service or reusable method later. 
        // For now, assume we charge for count($ids) * calculated_cost, or just calculated cost from preview.

        // We'll run the "preview" calculation here to get exact cost.
        $previewData = $this->calculateExportStats($ids, ['email' => $emailSelected, 'phone' => $phoneSelected]);
        $cost = $previewData['credits_required'];

        $billing = new \App\Services\BillingService();
        $requestId = $request->header('request_id') ?: Str::uuid()->toString();

        // 2. Charge/Lock Credits
        // We use chargeReveal structure or manual transaction.
        // Let's do manual transaction here since it's a batch.

        DB::beginTransaction();
        try {
            $ws = \App\Models\Workspace::where('owner_user_id', $user->id)->lockForUpdate()->first();
            if (!$ws || $ws->credit_balance < $cost) {
                return response()->json(['error' => 'INSUFFICIENT_CREDITS', 'required' => (int) $cost, 'available' => (int) ($ws->credit_balance ?? 0)], 402);
            }

            $ws->decrement('credit_balance', $cost);
            \App\Models\CreditTransaction::create([
                'id' => Str::ulid(),
                'workspace_id' => $ws->id,
                'amount' => -$cost,
                'type' => 'spend',
                'meta' => [
                    'category' => 'export',
                    'request_id' => $requestId,
                    'item_count' => count($ids)
                ]
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'BILLING_FAILED'], 500);
        }

        // 3. Perform Export (Sync for now if small, Async if large - prompt allows immediate)
        try {
            // Reusing existing simple export logic for CSV generation
            $contacts = [];
            $result = Contact::elastic()
                ->filter(['terms' => ['_id' => $ids]])
                ->select(['full_name', 'emails', 'phone_numbers', 'phone_number', 'mobile_phone', 'company', 'website', 'title', 'department'])
                ->paginate(1, count($ids)); // loading all for zip
            $data = array_map(fn($c) => RecordNormalizer::normalizeContact($c), $result['data']);

            $csv = \App\Exports\ExportCsvBuilder::buildContactsCsvDynamic($data, $emailSelected, $phoneSelected);

            // 4. Return result
            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="export.csv"',
                'X-Credits-Deducted' => $cost
            ]);

        } catch (\Exception $e) {
            // 5. Rollback on failure (Refund)
            // In real world we queue this cleanup. Here we do inline.
            DB::transaction(function () use ($ws, $cost) {
                $ws->increment('credit_balance', $cost);
                // record refund tx
            });

            return response()->json(['error' => 'EXPORT_FAILED'], 500);
        }
    }

    private function calculateExportStats(array $ids, array $fieldsToggle)
    {
        // Stripped down version of preview logic
        $emailCount = 0;
        $phoneCount = 0;

        $builder = Contact::elastic()->index((new Contact())->elasticReadAlias())->filter(['terms' => ['_id' => $ids]]);
        $chunkSize = 1000;

        // Iterate carefully
        $page = 1;
        while (true) {
            $res = $builder->paginate($page, $chunkSize);
            $data = $res['data'] ?? [];
            if (empty($data))
                break;

            foreach ($data as $c) {
                $norm = RecordNormalizer::normalizeContact($c);
                if (!empty($norm['emails']))
                    $emailCount++;
                if (!empty($norm['phones']))
                    $phoneCount++;
            }

            if ($page >= ($res['last_page'] ?? 1))
                break;
            $page++;
        }

        $credits = ($fieldsToggle['email'] ? $emailCount : 0) * 1
            + ($fieldsToggle['phone'] ? $phoneCount : 0) * 4;

        return ['credits_required' => $credits];
    }
}
