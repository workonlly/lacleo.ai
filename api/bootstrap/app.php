<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Auth\AuthenticationException;

$factory = function () {
return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\AppServiceProvider::class,
        \App\Providers\RouteServiceProvider::class,
        \App\Providers\AuthServiceProvider::class,
        \App\Providers\EventServiceProvider::class,
        \App\Providers\ConsoleServiceProvider::class,
        \App\Providers\TelescopeServiceProvider::class,
    ])
    ->withRouting(
            web: __DIR__.'/../routes/web.php',
            api: __DIR__.'/../routes/api.php',
            commands: __DIR__.'/../routes/console.php',
            health: '/up',
        )
        ->withCommands([
            \App\Console\Commands\GrantCredits::class,
            \App\Console\Commands\ReconcileCredits::class,
            \App\Console\Commands\SetupElasticModels::class,
            \App\Console\Commands\EsRestoreFromDb::class,
            \App\Console\Commands\ElasticSnapshot::class,
            \App\Console\Commands\ElasticRestore::class,
            \App\Console\Commands\ElasticAliasSwap::class,
            \App\Console\Commands\ElasticHealthCheck::class,
            \App\Console\Commands\ElasticStaticScan::class,
            
        ])
        ->withMiddleware(function (Middleware $middleware) {
            $middleware->alias([
                'ensureCreditsAvailable' => \App\Http\Middleware\EnsureCreditsAvailable::class,
                'ensureCreditsForExport' => \App\Http\Middleware\EnsureCreditsForExport::class,
                'ensureRevealFieldAvailable' => \App\Http\Middleware\EnsureRevealFieldAvailable::class,
                'admin' => \App\Http\Middleware\AdminOnly::class,
                'ensureWorkspace' => \App\Http\Middleware\EnsureWorkspace::class,
                'limit.request.size' => \App\Http\Middleware\LimitRequestBodySize::class,
                'request.timeout' => \App\Http\Middleware\RequestTimeout::class,
                'csrf.guard' => \App\Http\Middleware\CsrfGuard::class,
                'attachUserScope' => \App\Http\Middleware\AttachUserWorkspaceHeaders::class,
            ]);
            $middleware->prepend(HandleCors::class);
        })
        ->withExceptions(function (Exceptions $exceptions) {
            $exceptions->renderable(function (AuthenticationException $e, $request) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }
                return redirect()->guest('/login');
            });
        })->create();
};

$GLOBALS['__herd_closure'] = $factory;

return $factory();
