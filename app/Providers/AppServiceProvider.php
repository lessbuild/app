<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SocialSignIn\SocialiteSignInGateway;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SocialSignInGateway::class, SocialiteSignInGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
