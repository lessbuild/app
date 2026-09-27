<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\DnsResolver;
use App\Contracts\PaymentProvider;
use App\Contracts\RequestOrigin;
use App\Http\HttpRequestOrigin;
use App\Http\View\ShellComposer;
use App\Listeners\AuditSubscriber;
use App\Listeners\NotificationSubscriber;
use App\Models\ApiToken;
use App\Services\Billing\PaymentProviderFactory;
use App\Services\Dns\SystemDnsResolver;
use App\Services\SocialSignIn\SocialiteSignInGateway;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SocialSignInGateway::class, SocialiteSignInGateway::class);
        $this->app->bind(RequestOrigin::class, HttpRequestOrigin::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->singleton(PaymentProvider::class, fn ($app): PaymentProvider => PaymentProviderFactory::make($app['config']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(AuditSubscriber::class);
        Event::subscribe(NotificationSubscriber::class);
        View::composer('components.signal.layouts.app', ShellComposer::class);

        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
        RateLimiter::for('collect', fn (Request $request): Limit => Limit::perMinute((int) config('analytics.collect_rate_per_minute', 120))->by($request->ip().'|'.(string) $request->route('publicId')));
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by((string) ($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())));
    }
}
