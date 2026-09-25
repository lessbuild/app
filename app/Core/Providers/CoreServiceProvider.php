<?php

namespace App\Core\Providers;

use App\Core\Auth\PlatformUserProvider;
use App\Core\Contracts\ProductPlanResolver;
use App\Core\Http\Controllers\CorePlatformStatusController;
use App\Core\Http\Middleware\EnsurePlatformPasskeyRegistrationConfirmed;
use App\Core\Http\Middleware\RedirectProductGuestToPlatform;
use App\Core\Http\Middleware\ResolveProductPrincipal;
use App\Core\Models\Passkey;
use App\Core\Models\PlatformUser;
use App\Core\Services\Billing\ResolveProductPlan;
use App\Core\Services\Connections\ProjectConnectionDeliveryConsumerRegistry;
use App\Core\Services\Connections\ProjectConnectionDiagnosticRegistry;
use App\Core\Services\Connections\ProjectConnectionOutboxSourceRegistry;
use App\Core\Services\CustomerStatusPageProviderRegistry;
use App\Core\Services\Identity\ProductPrincipalProvisionerRegistry;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use App\Core\Services\Identity\ProductWorkspaceMembershipProjectorRegistry;
use App\Core\Services\Identity\ProductWorkspaceProvisionerRegistry;
use App\Core\Services\PlatformStatusProviderRegistry;
use App\Core\Services\ProductApiDocumentationRegistry;
use App\Core\Services\ProjectProductLinkRegistry;
use App\Core\Services\ProjectProductSummaryRegistry;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ProjectResourceLinkRegistry;
use App\Core\Services\ProjectSetupRegistry;
use App\Core\Services\ProjectTrafficContextRegistry;
use App\Core\Services\Search\WorkspaceSearchProviderRegistry;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use App\Core\Services\WorkspaceCostBreakdownProviderRegistry;
use App\Core\Services\WorkspaceCredentialProviderRegistry;
use App\Core\Services\WorkspaceCustomerStatusManagementProviderRegistry;
use App\Core\Services\WorkspaceFeedbackHistoryProviderRegistry;
use App\Core\Services\WorkspaceMonitorStatusManagementProviderRegistry;
use App\Core\Services\WorkspaceProductUsageProviderRegistry;
use App\Core\Services\WorkspaceWebhookDeliveryProviderRegistry;
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
        $this->app->singleton(ProjectConnectionDiagnosticRegistry::class);
        $this->app->singleton(ProjectConnectionDeliveryConsumerRegistry::class);
        $this->app->singleton(ProjectConnectionOutboxSourceRegistry::class);
        $this->app->singleton(ProjectProductSummaryRegistry::class);
        $this->app->singleton(ProjectResourceDestinationRegistry::class);
        $this->app->singleton(ProjectResourceLinkRegistry::class);
        $this->app->singleton(ProjectSetupRegistry::class);
        $this->app->singleton(ProjectTrafficContextRegistry::class);
        $this->app->singleton(CustomerStatusPageProviderRegistry::class);
        $this->app->singleton(PlatformStatusProviderRegistry::class);
        $this->app->singleton(ProductApiDocumentationRegistry::class);
        $this->app->singleton(WorkspaceSearchProviderRegistry::class);
        $this->app->singleton(WorkspaceActivityProviderRegistry::class);
        $this->app->singleton(WorkspaceCredentialProviderRegistry::class);
        $this->app->singleton(WorkspaceCostBreakdownProviderRegistry::class);
        $this->app->singleton(WorkspaceCustomerStatusManagementProviderRegistry::class);
        $this->app->singleton(WorkspaceMonitorStatusManagementProviderRegistry::class);
        $this->app->singleton(WorkspaceFeedbackHistoryProviderRegistry::class);
        $this->app->singleton(WorkspaceWebhookDeliveryProviderRegistry::class);
        $this->app->singleton(WorkspaceProductUsageProviderRegistry::class);
        $this->app->singleton(ProductPrincipalRegistry::class);
        $this->app->singleton(ProductPrincipalProvisionerRegistry::class);
        $this->app->singleton(ProductWorkspaceMembershipProjectorRegistry::class);
        $this->app->singleton(ProductWorkspaceProvisionerRegistry::class);
        $this->app->bind(ProductPlanResolver::class, ResolveProductPlan::class);

        Passkeys::useUserModel(PlatformUser::class);
        Passkeys::usePasskeyModel(Passkey::class);
        Passkeys::ignoreRoutes();
        $this->configurePasskeyOrigins();
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->booted(function (): void {
            if ($this->app->routesAreCached()) {
                return;
            }

            $registerStatusRoutes = static function (): void {
                Route::get('/status', [CorePlatformStatusController::class, 'show'])->name('core.status');
                Route::get('/status/report.json', [CorePlatformStatusController::class, 'report'])->name('core.status.report');
            };
            $dashboardHost = config('platform.dashboard_host');

            if (filled($dashboardHost)) {
                Route::domain($dashboardHost)->middleware('web')->group($registerStatusRoutes);

                return;
            }

            Route::middleware('web')->group($registerStatusRoutes);
        });

        $this->app['router']->aliasMiddleware('platform.principal', ResolveProductPrincipal::class);
        $this->app['router']->aliasMiddleware('platform.product-guest', RedirectProductGuestToPlatform::class);
        $this->app['router']->aliasMiddleware('platform.passkey-registration-confirmed', EnsurePlatformPasskeyRegistrationConfirmed::class);

        RateLimiter::for('platform.passkey', static fn (Request $request): Limit => Limit::perMinute(12)->by(
            'platform-passkey:'.$request->ip(),
        ));

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

        RateLimiter::for('platform.register', static fn (Request $request): Limit => Limit::perHour(5)->by(
            'platform-register:'.$request->ip(),
        ));

        RateLimiter::for('platform.social', static fn (Request $request): Limit => Limit::perMinute(12)->by(
            'platform-social:'.$request->ip(),
        ));

        RateLimiter::for('platform.sso.issue', static function (Request $request): array {
            $userId = (string) ($request->user('platform')?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(12)->by('platform-sso:'.$userId.'|'.$request->ip()),
                Limit::perMinute(60)->by('platform-sso-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('platform.sso.exchange', static fn (Request $request): Limit => Limit::perMinute(30)->by('platform-sso-exchange:'.$request->ip())
        );

        ResetPassword::createUrlUsing(static fn (PlatformUser $user, string $token): string => route('platform.password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        if (! $this->app->routesAreCached()) {
            $ssoRoutes = app_path('Core/Routes/sso.php');
            if (is_file($ssoRoutes)) {
                $hosts = $this->platformHosts();

                if ($hosts === []) {
                    Route::middleware('web')->group($ssoRoutes);
                } else {
                    foreach ($hosts as $host) {
                        Route::domain($host)->middleware('web')->group($ssoRoutes);
                    }
                }
            }
        }

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

    /** @return list<string> */
    private function platformHosts(): array
    {
        $configured = [
            config('app.url'),
            config('platform.auth_url'),
            config('platform.auth_host'),
            config('platform.dashboard_url'),
            config('platform.dashboard_host'),
        ];

        foreach (config('platform.products', []) as $product) {
            $configured[] = $product['url'] ?? null;
            $configured[] = $product['host'] ?? null;
        }

        $hosts = [];
        foreach ($configured as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $parts = parse_url(str_contains($value, '://') ? $value : 'https://'.$value);
            if (is_array($parts) && isset($parts['host'])) {
                $hosts[] = strtolower((string) $parts['host']);
            }
        }

        return array_values(array_unique($hosts));
    }

    private function configurePasskeyOrigins(): void
    {
        $configuredOrigins = config('passkeys.allowed_origins', []);
        $origins = is_array($configuredOrigins) ? array_values(array_filter($configuredOrigins, 'is_string')) : [];

        if ($origins === []) {
            $configuredUrls = [
                config('app.url'),
                config('platform.auth_url'),
                config('platform.dashboard_url'),
            ];

            foreach (config('platform.products', []) as $product) {
                $configuredUrls[] = $product['url'] ?? null;
                $configuredUrls[] = $product['host'] ?? null;
            }

            foreach ($configuredUrls as $value) {
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $parts = parse_url(str_contains($value, '://') ? $value : 'https://'.$value);
                if (! is_array($parts) || ! isset($parts['host'])) {
                    continue;
                }

                $origin = strtolower((string) ($parts['scheme'] ?? 'https')).'://'.strtolower((string) $parts['host']);
                if (isset($parts['port'])) {
                    $origin .= ':'.$parts['port'];
                }

                $origins[] = $origin;
            }
        }

        $relyingPartyValue = config('passkeys.relying_party_id')
            ?: config('platform.dashboard_host')
            ?: config('platform.dashboard_url')
            ?: config('platform.auth_host')
            ?: config('platform.auth_url')
            ?: config('app.url');
        $relyingPartyParts = is_string($relyingPartyValue)
            ? parse_url(str_contains($relyingPartyValue, '://') ? $relyingPartyValue : 'https://'.$relyingPartyValue)
            : false;
        $relyingPartyId = is_array($relyingPartyParts) && isset($relyingPartyParts['host'])
            ? strtolower((string) $relyingPartyParts['host'])
            : 'localhost';

        config([
            'passkeys.relying_party_id' => $relyingPartyId,
            'passkeys.allowed_origins' => array_values(array_unique($origins)),
            'passkeys.user_handle_secret' => config('passkeys.user_handle_secret') ?: config('app.key'),
            'passkeys.timeout' => (int) config('passkeys.timeout', 60000),
            'passkeys.guard' => 'platform',
        ]);
    }
}
