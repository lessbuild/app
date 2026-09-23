<?php

namespace App\Core\Providers;

use App\Core\Auth\PlatformUserProvider;
use App\Core\Models\Passkey;
use App\Core\Models\PlatformUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Passkeys;

final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        Passkeys::useUserModel(PlatformUser::class);
        Passkeys::usePasskeyModel(Passkey::class);
    }

    public function boot(): void
    {
        parent::boot();

        Auth::provider('core-platform', static fn ($app, array $config): PlatformUserProvider => new PlatformUserProvider(
            $app['hash'],
            $config['model'],
        ));

        $authenticationRoutes = app_path('Core/Routes/auth.php');

        if ($this->app->routesAreCached() || ! is_file($authenticationRoutes)) {
            return;
        }

        $registerRoutes = static fn () => Route::middleware('web')->group($authenticationRoutes);
        $host = config('platform.auth_host');

        if (filled($host)) {
            Route::domain($host)->group($registerRoutes);

            return;
        }

        $registerRoutes();
    }

    protected function modulePath(): string
    {
        return 'Core';
    }

    protected function moduleKey(): string
    {
        return 'core';
    }

    protected function routeHost(string $file): ?string
    {
        return config('platform.dashboard_host');
    }
}
