<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as BaseRouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends BaseRouteServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            // API routes
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            // Web routes
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * This registers both generic limiters and model-qualified limiters that
     * Laravel's ThrottleRequests middleware can resolve (e.g. "App\Models\User::api").
     *
     * @return void
     */
    protected function configureRateLimiting(): void
    {
        // Default API limiter (per authenticated user or IP)
        RateLimiter::for('api', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();
            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('App\\Models\\User::api', function (Request $request, $user = null) {
            if ($user && is_object($user) && property_exists($user, 'id')) {
                return Limit::perMinute(120)->by($user->id);
            }

            $key = optional($request->user())->id ?: $request->ip();
            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('search', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();
            return Limit::perMinute(60)->by($key);
        });

        // AI heavy operation limiter — more restrictive
        RateLimiter::for('ai', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();
            return Limit::perMinute(20)->by($key)->response(function () {
                return response()->json(['error' => 'rate_limited', 'message' => 'Too many AI requests.'], 429);
            });
        });

        // Reveal endpoints limiter — modest rate
        RateLimiter::for('reveal', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();
            return Limit::perMinute(30)->by($key);
        });

        // Export endpoints limiter — protect heavy exports
        RateLimiter::for('export', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();
            return Limit::perMinute(60)->by($key);
        });


        RateLimiter::for('App\\Models\\User::export', function (Request $request, $user = null) {
            if ($user && is_object($user) && property_exists($user, 'id')) {
                return Limit::perMinute(60)->by($user->id);
            }
            $key = optional($request->user())->id ?: $request->ip();
            return Limit::perMinute(60)->by($key);
        });
    }
}
