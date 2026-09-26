<?php

namespace App\Modules\Analytics\Providers;

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
        RateLimiter::for('collect', function (Request $request): array {
            $limit = config('analytics.collect_rate_per_minute', 120);
            $publicId = (string) $request->route('publicId');

            return [
                Limit::perMinute($limit)->by($request->ip()),
                Limit::perMinute($limit)->by($publicId.'|'.$request->ip()),
            ];
        });
    }
}
