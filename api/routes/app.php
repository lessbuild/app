<?php

declare(strict_types=1);

// The API behind the Nuxt app, under /api/app. These routes use the `web` middleware group: the session cookie
// signs people in, and writes need the X-XSRF-TOKEN header from the XSRF-TOKEN cookie. Sign-in, sign-up, two-factor
// and password reset are Fortify's routes under /api/app/auth (config/fortify.php).

use App\Http\Controllers\AccessRequests\ShowAccessRequestFormController;
use App\Http\Controllers\AccessRequests\StoreAccessRequestController;
use App\Http\Controllers\Account\ChangeMemberRoleController;
use App\Http\Controllers\Account\CheckProviderConnectionController;
use App\Http\Controllers\Account\CreateApiTokenController;
use App\Http\Controllers\Account\DeleteAccountController;
use App\Http\Controllers\Account\DeleteAuditStreamController;
use App\Http\Controllers\Account\DeleteProviderController;
use App\Http\Controllers\Account\DeleteWebhookEndpointController;
use App\Http\Controllers\Account\ExportAuditLogController;
use App\Http\Controllers\Account\InviteMemberController;
use App\Http\Controllers\Account\RemoveMemberController;
use App\Http\Controllers\Account\RenameAccountController;
use App\Http\Controllers\Account\RevokeApiTokenController;
use App\Http\Controllers\Account\RevokeInvitationController;
use App\Http\Controllers\Account\SendWebhookController;
use App\Http\Controllers\Account\ShowAccountSecurityController;
use App\Http\Controllers\Account\ShowAccountSettingsController;
use App\Http\Controllers\Account\ShowApiTokensController;
use App\Http\Controllers\Account\ShowAuditLogController;
use App\Http\Controllers\Account\ShowMembersController;
use App\Http\Controllers\Account\ShowProviderController;
use App\Http\Controllers\Account\ShowProvidersController;
use App\Http\Controllers\Account\ShowWebhooksController;
use App\Http\Controllers\Account\StoreAuditStreamController;
use App\Http\Controllers\Account\StoreProviderController;
use App\Http\Controllers\Account\StoreWebhookEndpointController;
use App\Http\Controllers\Account\SwitchAccountController;
use App\Http\Controllers\Account\UpdateAccountSecurityController;
use App\Http\Controllers\Account\UpdateMemberProjectsController;
use App\Http\Controllers\Account\UpdateMemberServicesController;
use App\Http\Controllers\Account\UpdateProviderController;
use App\Http\Controllers\Account\UpdateSamlSettingsController;
use App\Http\Controllers\Account\UpdateScimSettingsController;
use App\Http\Controllers\Account\UpdateWebhookEndpointController;
use App\Http\Controllers\Auth\ShowCurrentUserController;
use App\Http\Controllers\Auth\ShowSignInOptionsController;
use App\Http\Controllers\Auth\StartSsoSignInController;
use App\Http\Controllers\Dashboard\ShowDashboardController;
use App\Http\Controllers\Invitations\AcceptInvitationController;
use App\Http\Controllers\Invitations\ShowInvitationController;
use App\Http\Controllers\Projects\AddDomainController;
use App\Http\Controllers\Projects\CloneEnvironmentController;
use App\Http\Controllers\Projects\DeleteEnvironmentController;
use App\Http\Controllers\Projects\DeleteProjectController;
use App\Http\Controllers\Projects\DeleteProjectTemplateController;
use App\Http\Controllers\Projects\DisableProjectServiceController;
use App\Http\Controllers\Projects\DismissChecklistController;
use App\Http\Controllers\Projects\EnableProjectServiceController;
use App\Http\Controllers\Projects\RemoveDomainController;
use App\Http\Controllers\Projects\ShowDomainsController;
use App\Http\Controllers\Projects\ShowNewProjectController;
use App\Http\Controllers\Projects\ShowProjectController;
use App\Http\Controllers\Projects\ShowProjectServiceController;
use App\Http\Controllers\Projects\ShowProjectSettingsController;
use App\Http\Controllers\Projects\ShowProjectSetupController;
use App\Http\Controllers\Projects\ShowProjectTemplatesController;
use App\Http\Controllers\Projects\StoreEnvironmentController;
use App\Http\Controllers\Projects\StoreProjectController;
use App\Http\Controllers\Projects\StoreProjectFromTemplateController;
use App\Http\Controllers\Projects\StoreProjectTemplateController;
use App\Http\Controllers\Projects\StoreSampleProjectController;
use App\Http\Controllers\Projects\UpdateProjectController;
use App\Http\Controllers\Projects\VerifyDomainController;
use App\Http\Controllers\Shell\ShowShellController;
use Illuminate\Support\Facades\Route;

// Before signing in.
Route::get('/auth/options', ShowSignInOptionsController::class)->middleware('throttle:60,1')->name('auth.options');
Route::post('/auth/sso', StartSsoSignInController::class)->middleware(['guest', 'throttle:10,1'])->name('auth.sso');
Route::get('/access-requests', ShowAccessRequestFormController::class)->middleware('throttle:60,1')->name('access-requests.form');
Route::post('/access-requests', StoreAccessRequestController::class)->middleware('throttle:5,1')->name('access-requests.store');
Route::get('/invitations/{token}', ShowInvitationController::class)->where('token', '[A-Za-z0-9_-]{1,100}')->middleware('throttle:30,1')->name('invitations.show');

Route::get('/auth/me', ShowCurrentUserController::class)->middleware('auth')->name('auth.me');
Route::post('/auth/confirm-with/{provider}', App\Http\Controllers\Auth\ConfirmWithProviderController::class)->middleware(['auth', 'throttle:10,1'])->name('auth.confirm-with');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('/invitations/{token}', AcceptInvitationController::class)->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:10,1')->name('invitations.accept');
});

Route::middleware(['auth', 'verified', 'account.security'])->group(function (): void {
    Route::get('/shell', ShowShellController::class)->name('shell');
    Route::get('/dashboard', ShowDashboardController::class)->name('dashboard');

    Route::get('/projects/new', ShowNewProjectController::class)->name('projects.new');
    Route::post('/projects', StoreProjectController::class)->middleware('throttle:30,1')->name('projects.store');
    Route::post('/projects/sample', StoreSampleProjectController::class)->middleware(['account.can:create,App\\Models\\Project', 'throttle:5,1'])->name('projects.sample');
    Route::get('/projects/templates', ShowProjectTemplatesController::class)->middleware('account.can:create,App\\Models\\Project')->name('projects.templates');
    Route::post('/projects/templates', StoreProjectFromTemplateController::class)->middleware(['account.can:create,App\\Models\\Project', 'throttle:10,1'])->name('projects.templates.store');
    Route::delete('/projects/templates/{projectTemplate}', DeleteProjectTemplateController::class)->whereNumber('projectTemplate')->middleware(['account.can:create,App\\Models\\Project', 'throttle:20,1'])->name('projects.templates.destroy');

    Route::prefix('/projects/{project}')->middleware('project.context')->scopeBindings()->name('projects.')->group(function (): void {
        Route::get('/', ShowProjectController::class)->name('show');
        Route::put('/', UpdateProjectController::class)->name('update');
        Route::delete('/', DeleteProjectController::class)->middleware('password.confirm')->name('destroy');
        Route::get('/setup', ShowProjectSetupController::class)->name('setup');
        Route::delete('/checklist', DismissChecklistController::class)->name('checklist.dismiss');
        Route::post('/template', StoreProjectTemplateController::class)->middleware(['can:update,project', 'throttle:10,1'])->name('template.store');
        Route::get('/settings', ShowProjectSettingsController::class)->name('settings');
        Route::post('/environments', StoreEnvironmentController::class)->name('environments.store');
        Route::post('/environments/clone', CloneEnvironmentController::class)->middleware('throttle:10,1')->name('environments.clone');
        Route::delete('/environments/{environment}', DeleteEnvironmentController::class)->name('environments.destroy');
        Route::get('/domains', ShowDomainsController::class)->name('domains');
        Route::post('/domains', AddDomainController::class)->middleware('throttle:30,1')->name('domains.store');
        Route::post('/domains/{domain}/verify', VerifyDomainController::class)->middleware('throttle:20,1')->name('domains.verify');
        Route::delete('/domains/{domain}', RemoveDomainController::class)->name('domains.destroy');
        Route::get('/services/{service}', ShowProjectServiceController::class)->name('services.show');
        Route::post('/services/{service}', EnableProjectServiceController::class)->name('services.store');
        Route::delete('/services/{service}', DisableProjectServiceController::class)->name('services.destroy');
    });

    // The account: switching between accounts, members and invitations, and the account's own settings.
    Route::post('/accounts/{account}/switch', SwitchAccountController::class)->name('accounts.switch');
    Route::get('/account/members', ShowMembersController::class)->middleware('account.can:view')->name('account.members');
    Route::post('/account/invitations', InviteMemberController::class)->middleware('throttle:20,1')->name('account.invitations.store');
    Route::delete('/account/invitations/{invitation}', RevokeInvitationController::class)->name('account.invitations.destroy');
    Route::put('/account/members/{membership}', ChangeMemberRoleController::class)->name('account.members.update');
    Route::delete('/account/members/{membership}', RemoveMemberController::class)->name('account.members.destroy');
    Route::put('/account/members/{membership}/services', UpdateMemberServicesController::class)->name('account.members.services');
    Route::put('/account/members/{membership}/projects', UpdateMemberProjectsController::class)->name('account.members.projects');
    Route::get('/account/settings', ShowAccountSettingsController::class)->middleware('account.can:update')->name('account.settings');
    Route::put('/account/settings', RenameAccountController::class)->name('account.settings.update');
    Route::delete('/account/settings', DeleteAccountController::class)->middleware('password.confirm')->name('account.settings.destroy');

    // API tokens for /api/v1 and /api/v2: a token's value is shown once, when it's created.
    Route::get('/account/api-tokens', ShowApiTokensController::class)->middleware('account.can:manageApiTokens')->name('account.api-tokens');
    Route::post('/account/api-tokens', CreateApiTokenController::class)->middleware(['password.confirm', 'throttle:20,1'])->name('account.api-tokens.store');
    Route::delete('/account/api-tokens/{token}', RevokeApiTokenController::class)->whereNumber('token')->name('account.api-tokens.destroy');

    // The audit log: who changed what, exported as CSV, and streamed to Slack, a SIEM or S3 as it happens.
    Route::get('/account/audit-log', ShowAuditLogController::class)->middleware('account.can:viewAuditLog')->name('account.audit-log');
    Route::get('/account/audit-log/export', ExportAuditLogController::class)->middleware(['account.can:viewAuditLog', 'throttle:10,1'])->name('account.audit-log.export');
    Route::post('/account/audit-log/streams', StoreAuditStreamController::class)->middleware(['account.can:update', 'throttle:10,1'])->name('account.audit-log.streams.store');
    Route::delete('/account/audit-log/streams/{stream}', DeleteAuditStreamController::class)->whereNumber('stream')->middleware(['account.can:update', 'throttle:10,1'])->name('account.audit-log.streams.destroy');

    // Webhooks: signed events sent to the account's own endpoints, with each endpoint's recent deliveries.
    Route::get('/account/webhooks', ShowWebhooksController::class)->middleware('account.can:update')->name('account.webhooks');
    Route::post('/account/webhooks', StoreWebhookEndpointController::class)->middleware(['account.can:update', 'throttle:20,1'])->name('account.webhooks.store');
    Route::put('/account/webhooks/{endpoint}', UpdateWebhookEndpointController::class)->whereNumber('endpoint')->middleware(['account.can:update', 'throttle:30,1'])->name('account.webhooks.update');
    Route::delete('/account/webhooks/{endpoint}', DeleteWebhookEndpointController::class)->whereNumber('endpoint')->middleware(['account.can:update', 'throttle:20,1'])->name('account.webhooks.destroy');
    Route::post('/account/webhooks/{endpoint}/send', SendWebhookController::class)->whereNumber('endpoint')->middleware(['account.can:update', 'throttle:20,1'])->name('account.webhooks.send');

    // The account's sign-in rules, single sign-on (OpenID Connect or SAML) and SCIM provisioning.
    Route::get('/account/security', ShowAccountSecurityController::class)->middleware('account.can:update')->name('account.security');
    Route::put('/account/security', UpdateAccountSecurityController::class)->middleware(['account.can:update', 'password.confirm', 'throttle:20,1'])->name('account.security.update');
    Route::put('/account/security/scim', UpdateScimSettingsController::class)->middleware(['account.can:update', 'password.confirm', 'throttle:20,1'])->name('account.security.scim');
    Route::put('/account/security/saml', UpdateSamlSettingsController::class)->middleware(['account.can:update', 'password.confirm', 'throttle:20,1'])->name('account.security.saml');

    // Providers: credentials for the clouds that host servers, Cloudflare for DNS, and Git hosts.
    Route::get('/account/providers', ShowProvidersController::class)->middleware('can:viewAny,App\\Models\\Provider')->name('account.providers');
    Route::post('/account/providers', StoreProviderController::class)->middleware(['can:create,App\\Models\\Provider', 'throttle:20,1'])->name('account.providers.store');
    Route::get('/account/providers/{provider}', ShowProviderController::class)->whereNumber('provider')->middleware('can:view,provider')->name('account.providers.show');
    Route::put('/account/providers/{provider}', UpdateProviderController::class)->whereNumber('provider')->middleware(['can:update,provider', 'throttle:20,1'])->name('account.providers.update');
    Route::delete('/account/providers/{provider}', DeleteProviderController::class)->whereNumber('provider')->middleware(['can:delete,provider', 'password.confirm'])->name('account.providers.destroy');
    Route::post('/account/providers/{provider}/check', CheckProviderConnectionController::class)->whereNumber('provider')->middleware(['can:update,provider', 'throttle:10,1'])->name('account.providers.check');
});
