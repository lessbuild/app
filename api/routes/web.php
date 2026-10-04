<?php

declare(strict_types=1);

// Laravel's own web routes. Every page people see is the Nuxt app (web/); Laravel answers the JSON API
// (routes/api.php and routes/app.php), the admin panel, and the machine endpoints below, whose addresses are public
// contracts used by provisioning and deployment scripts, Stripe, the CLI, and status badges.

use App\Http\Controllers\Cli\DownloadCliController;
use App\Http\Controllers\Cli\ShowCliInstallerController;
use App\Http\Controllers\Deploy\RecordBuildCallbackController;
use App\Http\Controllers\Deploy\RecordDestructiveMigrationsController;
use App\Http\Controllers\Deploy\ReleaseBuildArtifactController;
use App\Http\Controllers\Deploy\WakeEnvironmentController;
use App\Http\Controllers\Infrastructure\RecordServerProvisioningController;
use App\Http\Controllers\Infrastructure\RecordWebsiteProvisioningController;
use App\Http\Controllers\Platform\ShowPlatformStatusReportController;
use App\Http\Controllers\Security\EvaluateSecurityGateController;
use App\Http\Controllers\Site\ShowStatusBadgeController;
use App\Http\Controllers\StatusPages\CheckStatusPageDomainController;
use App\Http\Controllers\StatusPages\ShowStatusPageBadgeController;
use App\Http\Controllers\StatusPages\ShowStatusPageReportController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/status/badge.svg', ShowStatusBadgeController::class)->middleware('throttle:120,1')->name('platform.status.badge');
Route::get('/status/report.json', ShowPlatformStatusReportController::class)->middleware('throttle:120,1')->name('platform.status.report');
Route::get('/status/{slug}/report.json', ShowStatusPageReportController::class)->where('slug', '[a-z0-9-]+')->middleware('throttle:120,1')->name('status.report');
Route::get('/status/{slug}/badge.svg', ShowStatusPageBadgeController::class)->where('slug', '[a-z0-9-]+')->middleware('throttle:240,1')->name('status.badge');
// Caddy asks before issuing a certificate for a customer's status page domain.
Route::get('/internal/tls/status-domain', CheckStatusPageDomainController::class)->name('status.domains.check');

Route::get('/cli/buildpusher', DownloadCliController::class)->middleware('throttle:60,1')->name('cli.download');
Route::get('/cli/install.sh', ShowCliInstallerController::class)->middleware('throttle:60,1')->name('cli.install');

// Provisioning scripts report here; signed, expiring, and CSRF-exempt.
Route::post('/servers/{serverId}/provisioning/callback/{event}', RecordServerProvisioningController::class)->whereNumber('serverId')->whereIn('event', ['status', 'failed', 'log'])
    ->middleware(['signed', 'throttle:600,1'])->name('callbacks.server');
Route::post('/websites/{websiteId}/provisioning/callback/{event}', RecordWebsiteProvisioningController::class)->whereNumber('websiteId')->whereIn('event', ['status', 'failed', 'log'])
    ->middleware(['signed', 'throttle:600,1'])->name('callbacks.website');

// Deployment scripts report here (a public contract); signed, expiring, and CSRF-exempt.
foreach (['status', 'failed', 'log', 'revision'] as $event) {
    Route::post("/builds/{build}/deployment/callback/{$event}", RecordBuildCallbackController::class)->whereNumber('build')->defaults('event', $event)
        ->middleware(['signed', 'throttle:1200,1'])->name("callbacks.build.{$event}");
}
Route::post('/builds/{build}/deployment/callback/artifact', ReleaseBuildArtifactController::class)->whereNumber('build')
    ->middleware(['signed', 'throttle:600,1'])->name('callbacks.build.artifact');
Route::post('/builds/{build}/deployment/callback/security', EvaluateSecurityGateController::class)->whereNumber('build')
    ->middleware(['signed', 'throttle:600,1'])->name('callbacks.build.security');
Route::post('/builds/{build}/deployment/callback/migrations', RecordDestructiveMigrationsController::class)->whereNumber('build')
    ->middleware(['signed', 'throttle:600,1'])->name('callbacks.build.migrations');
Route::post('/environments/{environment}/wake', WakeEnvironmentController::class)->middleware(['signed', 'throttle:60,1'])->name('callbacks.environment.wake');

Route::post('/webhooks/stripe', StripeWebhookController::class)->middleware('throttle:600,1')->name('webhooks.stripe');

// Browser round trips Laravel finishes itself before sending people back to a page: social sign-in, single sign-on,
// connecting Google and ad accounts, and referral links.
Route::get('/auth/{provider}/redirect', App\Http\Controllers\Auth\RedirectToProviderController::class)->middleware(['guest', 'throttle:20,1'])->name('social.redirect');
// Shared by sign-in, connecting a provider and sudo-mode confirmation; the controller tells them apart.
Route::get('/auth/{provider}/callback', App\Http\Controllers\Auth\HandleProviderCallbackController::class)->middleware('throttle:20,1')->name('social.callback');
Route::get('/sso/callback', App\Http\Controllers\Auth\SsoCallbackController::class)->middleware('throttle:20,1')->name('sso.callback');
Route::post('/sso/saml/acs', App\Http\Controllers\Auth\ConsumeSamlResponseController::class)->middleware('throttle:20,1')->name('sso.saml.acs');
Route::get('/sso/saml/finish', App\Http\Controllers\Auth\FinishSamlSignInController::class)->middleware('throttle:20,1')->name('sso.saml.finish');
Route::get('/sso/saml/{account}/metadata', App\Http\Controllers\Auth\ShowSamlMetadataController::class)->whereUlid('account')->middleware('throttle:60,1')->name('sso.saml.metadata');
// The signed link in getting-started emails: one-click unsubscribe posts here (the app shows the page for a GET).
Route::post('/email/getting-started/{user}/stop', App\Http\Controllers\Onboarding\StopGettingStartedEmailsController::class)->whereUlid('user')->middleware(['signed', 'throttle:20,1'])->name('getting-started-emails.stop.store');

// GitHub App installs: off to GitHub, and back (the App's Setup URL points at /github-app/callback).
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/github-app/connect', App\Http\Controllers\Deploy\ConnectGitHubAppController::class)->middleware(['can:create,App\\Models\\Provider', 'throttle:10,1'])->name('github-app.connect');
    Route::get('/github-app/callback', App\Http\Controllers\Deploy\CompleteGitHubAppInstallController::class)->middleware(['can:create,App\\Models\\Provider', 'throttle:10,1'])->name('github-app.callback');
});

Route::get('/r/{code}', App\Http\Controllers\Referrals\ShowReferralController::class)->where('code', '[A-Za-z0-9]{6,16}')->middleware('throttle:60,1')->name('referrals.show');

Route::middleware(['auth', 'verified', 'account.security'])->group(function (): void {
    Route::get('/sso/verify', App\Http\Controllers\Auth\StartSsoVerificationController::class)->middleware('throttle:10,1')->name('sso.verify');
    Route::get('/analytics/google-analytics/callback', App\Http\Controllers\Analytics\GoogleAnalyticsCallbackController::class)->middleware('throttle:10,1')->name('analytics.google-analytics.callback');
    Route::get('/analytics/ads/callback/{platform}', App\Http\Controllers\Analytics\AdPlatformCallbackController::class)->whereIn('platform', ['google', 'meta'])->middleware('throttle:10,1')->name('analytics.ads.callback');
    Route::get('/analytics/search-console/callback', App\Http\Controllers\Analytics\SearchConsoleCallbackController::class)->middleware('throttle:10,1')->name('analytics.search-console.callback');
    // The platform health report the admin panel links to.
    Route::get('/admin/health/report.json', App\Http\Controllers\Admin\ShowHealthReportController::class)->middleware(['platform.admin', 'password.confirm:password.confirm,900'])->name('admin.health.report');
});
