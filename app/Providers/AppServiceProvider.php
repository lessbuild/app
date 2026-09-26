<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Api\Models\ApiToken;
use App\Domain\Audit\Contracts\RequestOrigin;
use App\Domain\Audit\Listeners\AuditSubscriber;
use App\Http\HttpRequestOrigin;
use App\Http\View\ShellComposer;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(AuditSubscriber::class);
        View::composer('components.signal.layouts.app', ShellComposer::class);

        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by((string) ($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())));
    }
}
