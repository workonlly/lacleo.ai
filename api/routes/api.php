<?php

use App\Http\Controllers\Api\v1\AdminController;
use App\Http\Controllers\Api\v1\AiController;
use App\Http\Controllers\Api\v1\AiSearchController;
use App\Http\Controllers\Api\v1\BillingController;
use App\Http\Controllers\Api\v1\CompanyController;
use App\Http\Controllers\Api\v1\EnrichmentController;
use App\Http\Controllers\Api\v1\ExportController;
use App\Http\Controllers\Api\v1\LogoController;
use App\Http\Controllers\Api\v1\RevealController;
use App\Http\Controllers\Api\v1\SearchController;
use App\Http\Controllers\Api\v1\SavedFilterController;
use App\Http\Controllers\Api\v1\UserController;
use Illuminate\Support\Facades\Route;

Route::options('{any}', function () {
    return response()->json([], 204);
})->where('any', '.*');

// Version 1 API routes
// Public read-only endpoints to ensure frontend visibility during environment setup
Route::prefix('v1')->middleware([\App\Http\Middleware\RequestLogMiddleware::class])->group(function () {
    Route::get('/filters', [SearchController::class, 'getFilters']);
    Route::get('/filter/values', [SearchController::class, 'getFilterValues']);
    Route::get('/search/{type}', [SearchController::class, 'search'])->middleware(app()->environment(['testing', 'local']) ? [] : 'throttle:search');


    Route::post('/ai/generate-filters', [AiController::class, 'generateFilters'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:App\\Models\\User::api',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));

    Route::post('/ai/translate-query', [AiSearchController::class, 'translate'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:api',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));

    Route::get('/company/social', [CompanyController::class, 'social']);
    Route::get('/logo', [LogoController::class, 'getLogo']);
    Route::post('/billing/webhook/stripe', [BillingController::class, 'webhookStripe'])
        ->middleware(['request.timeout', 'limit.request.size']);
});

// Protected endpoints remain behind authentication
Route::prefix('v1')->middleware([\App\Http\Middleware\RequestLogMiddleware::class, 'auth:sanctum', 'ensureWorkspace'])->group(function () {
    Route::get('/user', [UserController::class, 'getUser']);
    Route::get('/users/search', [UserController::class, 'search'])->middleware('admin');
    Route::get('/admin/debug/billing-context', [AdminController::class, 'billingContext'])->middleware('admin');
    Route::get('/admin/debug/search', [SearchController::class, 'debugQuery'])->middleware('admin');
    Route::middleware('verified')->group(function () {
        // other protected routes
    });
    Route::get('/billing/usage', [BillingController::class, 'usage']);
    Route::post('/billing/grant-credits', [BillingController::class, 'grantCredits'])->middleware(['verified', 'admin']);
    Route::post('/billing/purchase', [BillingController::class, 'purchase'])->middleware(['request.timeout', 'csrf.guard']);
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->middleware(['request.timeout', 'csrf.guard']);
    Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware(['request.timeout', 'csrf.guard']);
    Route::post('/ai/generate', [AiController::class, 'generateFilters'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:App\\Models\\User::api',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::post('/ai/contact-summary', [AiController::class, 'contactSummary'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:ai',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::post('/ai/company-summary', [AiController::class, 'companySummary'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:ai',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    // Unified reveal endpoints: per-contact endpoint handles combined reveals and charging.
    Route::post('/reveal/email', [RevealController::class, 'email'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:reveal',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]))
        ->middleware('ensureRevealFieldAvailable:email');

    Route::post('/reveal/phone', [RevealController::class, 'phone'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:reveal',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]))
        ->middleware('ensureRevealFieldAvailable:phone');
    Route::post('/contacts/{id}/reveal', [RevealController::class, 'revealContact'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:reveal',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::post('/companies/{id}/reveal', [RevealController::class, 'revealCompany'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:reveal',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::post('/contact-enrichment', [EnrichmentController::class, 'enrichContact'])->middleware('ensureCreditsAvailable:enrichment,1');
    Route::post('/billing/preview-export', [ExportController::class, 'preview'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:export',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::post('/billing/export', [ExportController::class, 'export'])
        ->middleware(array_filter([
            'ensureCreditsForExport',
            app()->environment('testing') ? null : 'throttle:export',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::get('/billing/export/download/{requestId}', [ExportController::class, 'downloadDirect']);
    Route::post('/billing/export-query', [ExportController::class, 'exportByQuery'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:export',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::get('/filters/companies/suggest', [\App\Http\Controllers\Api\v1\FilterSuggestController::class, 'companies'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:suggest',
            'request.timeout',
        ]));
    Route::get('/filters/companies/existence', [\App\Http\Controllers\Api\v1\FilterSuggestController::class, 'existence'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:suggest',
            'request.timeout',
        ]));
    Route::post('/filters/companies/existence', [\App\Http\Controllers\Api\v1\FilterSuggestController::class, 'existencePost'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:suggest',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::post('/companies/check-existence', [\App\Http\Controllers\Api\v1\FilterSuggestController::class, 'existencePost'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:suggest',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::post('/filters/bulk-apply', [\App\Http\Controllers\Api\v1\FilterSuggestController::class, 'bulkApply'])
        ->middleware(array_filter([
            app()->environment('testing') ? null : 'throttle:suggest',
            'limit.request.size',
            'request.timeout',
            'csrf.guard',
        ]));
    Route::post('/company/{id}/social/refresh', [CompanyController::class, 'refreshSocial'])->middleware('admin');
    Route::get('/contact-enrichment/{requestId}', [EnrichmentController::class, 'show']);

    // Saved Filters
    Route::get('/saved-filters', [SavedFilterController::class, 'index']);
    Route::post('/saved-filters', [SavedFilterController::class, 'store']);
    Route::delete('/saved-filters/{id}', [SavedFilterController::class, 'destroy']);
    Route::put('/saved-filters/{id}', [SavedFilterController::class, 'update']);
});
