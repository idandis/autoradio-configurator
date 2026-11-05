<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('autogenesi', function (Request $request) {
            if (app()->environment('local') && $request->user()?->is_admin) {
                return Limit::none();
            }

            return Limit::perMinutes(10, $request->routeIs('autogenesi.edit-image') ? 6 : 3)
                ->by($request->route()?->getName().'|'.($request->user()?->id ?? $request->ip()));
        });
        // Avoid prefetching every application page: shared hosting can throttle
        // the resulting burst and return empty/503 JavaScript modules.
    }
}
