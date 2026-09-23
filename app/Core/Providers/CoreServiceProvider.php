<?php

namespace App\Core\Providers;

use App\Core\Auth\PlatformUserProvider;
use App\Core\Contracts\ProductPlanResolver;
use App\Core\Http\Middleware\RedirectProductGuestToPlatform;
use App\Core\Http\Middleware\ResolveProductPrincipal;
use App\Core\Models\Passkey;
use App\Core\Models\PlatformUser;
use App\Core\Services\Billing\ResolveProductPlan;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use App\Core\Services\ProjectProductLinkRegistry;
use App\Core\Services\ProjectProductSummaryRegistry;
use App\Core\Services\ProjectResourceLinkRegistry;
use App\Core\Services\ProjectSetupRegistry;
use App\Core\Services\ProjectTrafficContextRegistry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Passkeys;

final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProjectProductLinkRegistry::class);
        $this->app->singleton(ProjectProductSummaryRegistry::class);
        $this->app->singleton(ProjectResourceLinkRegistry::class);
        $this->app->singleton(ProjectSetupRegistry::class);
        $this->app->singleton(ProjectTrafficContextRegistry::class);
        $this->app->singleton(ProductPrincipalRegistry::class);
        $this->app->bind(ProductPlanResolver::class, ResolveProductPlan::class);

        Passkeys::useUserModel(PlatformUser::class);
        Passkeys::usePasskeyModel(Passkey::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app['router']->aliasMiddleware('platform.principal', ResolveProductPrincipal::class);
        $this->app['router']->aliasMiddleware('platform.product-guest', RedirectProductGuestToPlatform::class);

        Auth::provider('core-platform', static fn ($app, array $config): PlatformUserProvider => new PlatformUserProvider(
            $app['hash'],
            $config['model'],
        ));

        RateLimiter::for('platform.login', static function (Request $request): array {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';

            return [
                Limit::perMinute(5)->by('platform-login:'.hash('sha256', $email.'|'.$request->ip())),
                Limit::perMinute(30)->by('platform-login-ip:'.$request->ip()),
            ];
        });

        ResetPassword::createUrlUsing(static fn (PlatformUser $user, string $token): string => route('platform.password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        $authenticationRoutes = app_path('Core/Routes/auth.php');

        if ($this->app->routesAreCached() || ! is_file($authenticationRoutes)) {
            return;
        }

        $registerRoutes = static fn () => Route::middleware('web')->name('platform.')->group($authenticationRoutes);
        $host = config('platform.auth_host');

        if (filled($host)) {
            Route::domain($host)->group($registerRoutes);

            return;
        }

        Route::prefix('platform')->group($registerRoutes);
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
