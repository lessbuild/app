<?php

namespace App\Providers;

use App\Models\Server;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot(): void
    {
        Route::model('server', Server::class);

        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting(): void
    {
        // Authentication is prioritized before throttling, allowing workspace plans to share one quota.
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();
            $organization = $user instanceof User ? $user->currentOrganization : null;
            $plan = $organization?->owner?->billingPlan() ?? ($user instanceof User ? $user->billingPlan() : 'free');
            $perMinute = (int) config("billing.plans.{$plan}.limits.api_requests_per_minute", 60);
            $key = $organization
                ? 'organization:'.$organization->id
                : 'ip:'.$request->ip();

            return Limit::perMinute(max(1, $perMinute))->by($key);
        });

        RateLimiter::for('sensitive-account', function (Request $request): Limit|array {
            if ($request->user()) {
                return Limit::perMinute(6)->by('user:'.$request->user()->getAuthIdentifier());
            }

            $ip = $request->ip();
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(20)->by('ip:'.$ip),
                Limit::perMinute(6)->by('credential:'.hash('sha256', "{$ip}|{$email}")),
            ];
        });

        RateLimiter::for('access-requests', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perHour(10)->by('access-ip:'.$request->ip()),
                Limit::perDay(3)->by('access-email:'.hash('sha256', $email)),
            ];
        });
    }
}
