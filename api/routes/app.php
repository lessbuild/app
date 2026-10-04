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
use App\Http\Controllers\Account\DeleteClientController;
use App\Http\Controllers\Account\DeleteProviderController;
use App\Http\Controllers\Account\DeleteWebhookEndpointController;
use App\Http\Controllers\Account\ExportAuditLogController;
use App\Http\Controllers\Account\ExportClientCostsController;
use App\Http\Controllers\Account\ExportInventoryController;
use App\Http\Controllers\Account\InviteMemberController;
use App\Http\Controllers\Account\RemoveMemberController;
use App\Http\Controllers\Account\RenameAccountController;
use App\Http\Controllers\Account\RevokeApiTokenController;
use App\Http\Controllers\Account\RevokeInvitationController;
use App\Http\Controllers\Account\SaveClientController;
use App\Http\Controllers\Account\SendWebhookController;
use App\Http\Controllers\Account\ShowAccountSecurityController;
use App\Http\Controllers\Account\ShowAccountSettingsController;
use App\Http\Controllers\Account\ShowApiTokensController;
use App\Http\Controllers\Account\ShowAuditLogController;
use App\Http\Controllers\Account\ShowClientReportController;
use App\Http\Controllers\Account\ShowClientsController;
use App\Http\Controllers\Account\ShowMembersController;
use App\Http\Controllers\Account\ShowProviderController;
use App\Http\Controllers\Account\ShowProvidersController;
use App\Http\Controllers\Account\ShowWebhooksController;
use App\Http\Controllers\Account\StoreAuditStreamController;
use App\Http\Controllers\Account\StoreProviderController;
use App\Http\Controllers\Account\StoreWebhookEndpointController;
use App\Http\Controllers\Account\SwitchAccountController;
use App\Http\Controllers\Account\UpdateAccountSecurityController;
use App\Http\Controllers\Account\UpdateBrandingController;
use App\Http\Controllers\Account\UpdateMemberProjectsController;
use App\Http\Controllers\Account\UpdateMemberServicesController;
use App\Http\Controllers\Account\UpdateProviderController;
use App\Http\Controllers\Account\UpdateSamlSettingsController;
use App\Http\Controllers\Account\UpdateScimSettingsController;
use App\Http\Controllers\Account\UpdateWebhookEndpointController;
use App\Http\Controllers\Auth\ConnectProviderController;
use App\Http\Controllers\Auth\DisconnectProviderController;
use App\Http\Controllers\Auth\ShowCurrentUserController;
use App\Http\Controllers\Auth\ShowSignInOptionsController;
use App\Http\Controllers\Auth\StartSsoSignInController;
use App\Http\Controllers\Billing\ChangePlanController;
use App\Http\Controllers\Billing\OpenBillingPortalController;
use App\Http\Controllers\Billing\ResumePlanController;
use App\Http\Controllers\Billing\ShowBillingController;
use App\Http\Controllers\Billing\UpdateBillingIntervalController;
use App\Http\Controllers\Billing\UpdatePayAsYouGoController;
use App\Http\Controllers\Dashboard\ShowDashboardController;
use App\Http\Controllers\Deploy\ApplyConfigurationReviewController;
use App\Http\Controllers\Deploy\ApplyWorkflowController;
use App\Http\Controllers\Deploy\ApproveMigrationsController;
use App\Http\Controllers\Deploy\ApprovePreviewSecretsController;
use App\Http\Controllers\Deploy\CancelBuildController;
use App\Http\Controllers\Deploy\CancelScheduledDeployController;
use App\Http\Controllers\Deploy\ClosePreviewController;
use App\Http\Controllers\Deploy\CreateRepositoryController;
use App\Http\Controllers\Deploy\DecideVariableChangeController;
use App\Http\Controllers\Deploy\DeleteEnvironmentSettingController;
use App\Http\Controllers\Deploy\DeletePipelineController;
use App\Http\Controllers\Deploy\DeleteRepositoryController;
use App\Http\Controllers\Deploy\MoveEnvironmentRecipeController;
use App\Http\Controllers\Deploy\OpenBranchPreviewController;
use App\Http\Controllers\Deploy\PlanConfigurationController;
use App\Http\Controllers\Deploy\PromoteBuildController;
use App\Http\Controllers\Deploy\RedeployBuildController;
use App\Http\Controllers\Deploy\RefreshEnvironmentRecipeController;
use App\Http\Controllers\Deploy\ReplaceEnvironmentVariablesController;
use App\Http\Controllers\Deploy\RetryPreviewCleanupController;
use App\Http\Controllers\Deploy\ReviewBuildController;
use App\Http\Controllers\Deploy\RollbackBuildController;
use App\Http\Controllers\Deploy\RunEnvironmentRecipesController;
use App\Http\Controllers\Deploy\RunPipelineController;
use App\Http\Controllers\Deploy\RunScheduledTaskController;
use App\Http\Controllers\Deploy\ShowBuildComparisonController;
use App\Http\Controllers\Deploy\ShowBuildController;
use App\Http\Controllers\Deploy\ShowBuildStatusController;
use App\Http\Controllers\Deploy\ShowConfigurationApplicationController;
use App\Http\Controllers\Deploy\ShowConfigurationController;
use App\Http\Controllers\Deploy\ShowConfigurationReviewController;
use App\Http\Controllers\Deploy\ShowDeployDecisionController;
use App\Http\Controllers\Deploy\ShowDeployEnvironmentController;
use App\Http\Controllers\Deploy\ShowDeployEnvironmentsController;
use App\Http\Controllers\Deploy\ShowGitHubAppRepositoriesController;
use App\Http\Controllers\Deploy\ShowPipelinesController;
use App\Http\Controllers\Deploy\ShowPreviewsController;
use App\Http\Controllers\Deploy\ShowRepositoriesController;
use App\Http\Controllers\Deploy\ShowRepositoryController;
use App\Http\Controllers\Deploy\ShowScheduledTaskRunController;
use App\Http\Controllers\Deploy\StoreBuildController;
use App\Http\Controllers\Deploy\StoreConfigurationReviewController;
use App\Http\Controllers\Deploy\StoreDeploymentScheduleController;
use App\Http\Controllers\Deploy\StoreEnvironmentFreezeController;
use App\Http\Controllers\Deploy\StoreEnvironmentProcessController;
use App\Http\Controllers\Deploy\StoreEnvironmentRecipeController;
use App\Http\Controllers\Deploy\StoreEnvironmentResourceController;
use App\Http\Controllers\Deploy\StoreEnvironmentVariableController;
use App\Http\Controllers\Deploy\StorePipelineController;
use App\Http\Controllers\Deploy\StoreRepositoryController;
use App\Http\Controllers\Deploy\StoreScalingScheduleController;
use App\Http\Controllers\Deploy\StoreScheduledDeployController;
use App\Http\Controllers\Deploy\StoreScheduledTaskController;
use App\Http\Controllers\Deploy\StoreSecretSyncController;
use App\Http\Controllers\Deploy\UpdateBuildCacheController;
use App\Http\Controllers\Deploy\UpdateConfigurationOperationController;
use App\Http\Controllers\Deploy\UpdateDeploymentControlsController;
use App\Http\Controllers\Deploy\UpdateEnvironmentBuildServerController;
use App\Http\Controllers\Deploy\UpdateEnvironmentDeployNotificationsController;
use App\Http\Controllers\Deploy\UpdateEnvironmentDeploySettingsController;
use App\Http\Controllers\Deploy\UpdateEnvironmentHibernationController;
use App\Http\Controllers\Deploy\UpdateEnvironmentMaintenanceController;
use App\Http\Controllers\Deploy\UpdateEnvironmentRecipeSettingsController;
use App\Http\Controllers\Deploy\UpdateEnvironmentRuntimeController;
use App\Http\Controllers\Deploy\UpdateReleaseNotesPageController;
use App\Http\Controllers\Deploy\UpdateRepositoryController;
use App\Http\Controllers\Deploy\UpdateRepositoryPreviewsController;
use App\Http\Controllers\Deploy\UpdateRepositoryWebhookController;
use App\Http\Controllers\Deploy\UpdateSecretSyncController;
use App\Http\Controllers\Feedback\StoreFeedbackController;
use App\Http\Controllers\Invitations\AcceptInvitationController;
use App\Http\Controllers\Invitations\ShowInvitationController;
use App\Http\Controllers\Notifications\ExportNotificationsController;
use App\Http\Controllers\Notifications\MarkAllNotificationsReadController;
use App\Http\Controllers\Notifications\OpenNotificationController;
use App\Http\Controllers\Notifications\ShowNotificationsController;
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
use App\Http\Controllers\SavedViews\DeleteSavedViewController;
use App\Http\Controllers\SavedViews\ShowSavedViewsController;
use App\Http\Controllers\SavedViews\StoreSavedViewController;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\Services\ShowServiceController;
use App\Http\Controllers\Settings\DeletePushDeviceController;
use App\Http\Controllers\Settings\DeleteSshKeyController;
use App\Http\Controllers\Settings\DeleteUserController;
use App\Http\Controllers\Settings\ExportPersonalDataController;
use App\Http\Controllers\Settings\SendTestPushController;
use App\Http\Controllers\Settings\ShowNotificationSettingsController;
use App\Http\Controllers\Settings\ShowPrivacyController;
use App\Http\Controllers\Settings\ShowProfileController;
use App\Http\Controllers\Settings\ShowSecurityController;
use App\Http\Controllers\Settings\ShowSessionsController;
use App\Http\Controllers\Settings\SignOutBrowserController;
use App\Http\Controllers\Settings\SignOutOtherBrowsersController;
use App\Http\Controllers\Settings\StorePushDeviceController;
use App\Http\Controllers\Settings\StoreSshKeyController;
use App\Http\Controllers\Settings\UpdateGettingStartedEmailsController;
use App\Http\Controllers\Settings\UpdateNotificationSettingsController;
use App\Http\Controllers\Settings\UpdateWeeklyReportEmailsController;
use App\Http\Controllers\Shell\ShowShellController;
use Illuminate\Support\Facades\Route;

// Before signing in.
Route::get('/auth/options', ShowSignInOptionsController::class)->middleware('throttle:60,1')->name('auth.options');
Route::post('/auth/sso', StartSsoSignInController::class)->middleware(['guest', 'throttle:10,1'])->name('auth.sso');
Route::get('/access-requests', ShowAccessRequestFormController::class)->middleware('throttle:60,1')->name('access-requests.form');
Route::post('/access-requests', StoreAccessRequestController::class)->middleware('throttle:5,1')->name('access-requests.store');
Route::get('/releases/{token}', App\Http\Controllers\Deploy\ShowPublicReleaseNotesController::class)->where('token', '[a-z0-9]{32}')->middleware('throttle:120,1')->name('deploy.release-notes.public');
Route::get('/invitations/{token}', ShowInvitationController::class)->where('token', '[A-Za-z0-9_-]{1,100}')->middleware('throttle:30,1')->name('invitations.show');

Route::get('/auth/me', ShowCurrentUserController::class)->middleware('auth')->name('auth.me');
Route::post('/auth/confirm-with/{provider}', App\Http\Controllers\Auth\ConfirmWithProviderController::class)->middleware(['auth', 'throttle:10,1'])->name('auth.confirm-with');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('/invitations/{token}', AcceptInvitationController::class)->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:10,1')->name('invitations.accept');
});

// Personal settings: the person's own profile, security, sessions, notifications and data. Outside the account's
// security rules, so someone a rule blocks can always put things right.
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/settings/profile', ShowProfileController::class)->name('settings.profile');
    Route::get('/settings/security', ShowSecurityController::class)->middleware('password.confirm')->name('settings.security');
    Route::post('/settings/ssh-keys', StoreSshKeyController::class)->middleware(['password.confirm', 'throttle:20,1'])->name('settings.ssh-keys.store');
    Route::delete('/settings/ssh-keys/{key}', DeleteSshKeyController::class)->whereNumber('key')->middleware('password.confirm')->name('settings.ssh-keys.destroy');
    Route::post('/settings/security/social/{provider}', ConnectProviderController::class)->middleware(['password.confirm', 'throttle:10,1'])->name('social.connect');
    Route::delete('/settings/security/social/{provider}', DisconnectProviderController::class)->middleware('password.confirm')->name('social.disconnect');
    Route::get('/settings/sessions', ShowSessionsController::class)->name('settings.sessions');
    Route::delete('/settings/sessions', SignOutOtherBrowsersController::class)->name('settings.sessions.destroy-others');
    Route::delete('/settings/sessions/{session}', SignOutBrowserController::class)->name('settings.sessions.destroy');
    Route::get('/settings/notifications', ShowNotificationSettingsController::class)->name('settings.notifications');
    Route::put('/settings/notifications', UpdateNotificationSettingsController::class)->name('settings.notifications.update');
    Route::put('/settings/notifications/getting-started', UpdateGettingStartedEmailsController::class)->name('settings.getting-started-emails.update');
    Route::put('/settings/notifications/weekly-report', UpdateWeeklyReportEmailsController::class)->name('settings.weekly-report-emails.update');
    Route::post('/settings/push-devices', StorePushDeviceController::class)->middleware('throttle:20,1')->name('settings.push-devices.store');
    Route::post('/settings/push-devices/test', SendTestPushController::class)->middleware('throttle:6,1')->name('settings.push-devices.test');
    Route::delete('/settings/push-devices/{device}', DeletePushDeviceController::class)->whereNumber('device')->name('settings.push-devices.destroy');
    Route::get('/settings/privacy', ShowPrivacyController::class)->name('settings.privacy');
    Route::get('/settings/privacy/export', ExportPersonalDataController::class)->middleware('throttle:6,1')->name('settings.privacy.export');
    Route::delete('/settings/privacy/user', DeleteUserController::class)->middleware('password.confirm')->name('settings.privacy.destroy');
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

    // A service across the account: the projects using it, and turning it on for others.
    Route::get('/services/{service}', ShowServiceController::class)->middleware('account.can:useService,service')->name('services.show');

    // Searching the account (the command palette), and the person's saved views of filtered pages.
    Route::get('/search', SearchController::class)->middleware('throttle:120,1')->name('search');
    Route::get('/saved-views', ShowSavedViewsController::class)->name('saved-views.index');
    Route::post('/saved-views', StoreSavedViewController::class)->middleware('throttle:30,1')->name('saved-views.store');
    Route::delete('/saved-views/{view}', DeleteSavedViewController::class)->whereNumber('view')->name('saved-views.destroy');

    // Feedback from anywhere in the app, to the admins.
    Route::post('/feedback', StoreFeedbackController::class)->middleware('throttle:10,1')->name('feedback.store');

    // The person's notifications, across their accounts.
    Route::get('/notifications', ShowNotificationsController::class)->name('notifications.index');
    Route::get('/notifications/export', ExportNotificationsController::class)->middleware('throttle:10,1')->name('notifications.export');
    Route::post('/notifications/read', MarkAllNotificationsReadController::class)->name('notifications.read');
    Route::post('/notifications/{notification}/open', OpenNotificationController::class)->whereUuid('notification')->name('notifications.open');

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

    // Billing: a plan for each service, paid monthly or yearly, with pay-as-you-go past the allowances.
    Route::get('/account/billing', ShowBillingController::class)->middleware('account.can:viewBilling')->name('account.billing');
    Route::post('/account/billing/portal', OpenBillingPortalController::class)->name('account.billing.portal');
    Route::put('/account/billing/interval', UpdateBillingIntervalController::class)->middleware('throttle:10,1')->name('account.billing.interval');
    Route::post('/account/billing/{service}', ChangePlanController::class)->middleware('throttle:20,1')->name('account.billing.change');
    Route::post('/account/billing/{service}/resume', ResumePlanController::class)->name('account.billing.resume');
    Route::put('/account/billing/{service}/usage', UpdatePayAsYouGoController::class)->middleware('throttle:20,1')->name('account.billing.usage');

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

    // The repositories a GitHub App installation can reach, to connect in a project.
    Route::get('/github-app/providers/{provider}/repositories', ShowGitHubAppRepositoriesController::class)->whereNumber('provider')->middleware(['can:view,provider', 'throttle:20,1'])->name('github-app.repositories');

    // Providers: credentials for the clouds that host servers, Cloudflare for DNS, and Git hosts.
    Route::get('/account/providers', ShowProvidersController::class)->middleware('can:viewAny,App\\Models\\Provider')->name('account.providers');
    Route::post('/account/providers', StoreProviderController::class)->middleware(['can:create,App\\Models\\Provider', 'throttle:20,1'])->name('account.providers.store');
    Route::get('/account/providers/{provider}', ShowProviderController::class)->whereNumber('provider')->middleware('can:view,provider')->name('account.providers.show');
    Route::put('/account/providers/{provider}', UpdateProviderController::class)->whereNumber('provider')->middleware(['can:update,provider', 'throttle:20,1'])->name('account.providers.update');
    Route::delete('/account/providers/{provider}', DeleteProviderController::class)->whereNumber('provider')->middleware(['can:delete,provider', 'password.confirm'])->name('account.providers.destroy');
    Route::post('/account/providers/{provider}/check', CheckProviderConnectionController::class)->whereNumber('provider')->middleware(['can:update,provider', 'throttle:10,1'])->name('account.providers.check');

    // Clients, for agencies: white-label branding, monthly reports and costs by client with a markup.
    Route::get('/account/clients', ShowClientsController::class)->middleware('account.can:update')->name('account.clients');
    Route::put('/account/clients/branding', UpdateBrandingController::class)->middleware(['account.can:update', 'throttle:20,1'])->name('account.clients.branding');
    Route::get('/account/clients/costs.csv', ExportClientCostsController::class)->middleware(['account.can:update', 'throttle:10,1'])->name('account.clients.costs');
    Route::post('/account/clients', SaveClientController::class)->middleware(['account.can:update', 'throttle:30,1'])->name('account.clients.store');
    Route::put('/account/clients/{client}', SaveClientController::class)->whereNumber('client')->middleware(['account.can:update', 'throttle:30,1'])->name('account.clients.update');
    Route::delete('/account/clients/{client}', DeleteClientController::class)->whereNumber('client')->middleware(['account.can:update', 'throttle:30,1'])->name('account.clients.destroy');
    Route::get('/account/clients/{client}/report', ShowClientReportController::class)->whereNumber('client')->middleware('account.can:update')->name('account.clients.report');

    // Inventories of the account's servers, websites, providers, recipes and repositories, as CSV.
    Route::get('/account/inventory/{kind}.csv', ExportInventoryController::class)->whereIn('kind', App\Queries\Accounts\InventoryQuery::KINDS)->middleware('throttle:20,1')->name('account.inventory');
});

// Deploy: repositories and their deploys, previews, pipelines, environments' deploy settings and configuration.
Route::middleware(['auth', 'verified', 'account.security'])->prefix('/projects/{project}')->middleware('project.context')->scopeBindings()->group(function (): void {
    Route::prefix('/deploy')->middleware('service:deploy')->name('deploy.')->group(function (): void {
        Route::get('/', ShowRepositoriesController::class)->name('repositories');
        Route::get('/pipelines', ShowPipelinesController::class)->name('pipelines');
        Route::post('/pipelines', StorePipelineController::class)->middleware('throttle:20,1')->name('pipelines.store');
        Route::post('/pipelines/{pipeline}/run', RunPipelineController::class)->whereNumber('pipeline')->middleware('throttle:20,1')->name('pipelines.run');
        Route::delete('/pipelines/{pipeline}', DeletePipelineController::class)->whereNumber('pipeline')->middleware('throttle:20,1')->name('pipelines.destroy');
        Route::get('/repositories/create', CreateRepositoryController::class)->middleware('can:create,App\\Models\\Repository,project')->name('repositories.create');
        Route::post('/repositories', StoreRepositoryController::class)->middleware(['can:create,App\\Models\\Repository,project', 'throttle:20,1'])->name('repositories.store');
        Route::get('/repositories/{repository}', ShowRepositoryController::class)->whereNumber('repository')->middleware('can:view,repository')->name('repositories.show');
        Route::put('/repositories/{repository}', UpdateRepositoryController::class)->whereNumber('repository')->middleware(['can:update,repository', 'throttle:20,1'])->name('repositories.update');
        Route::put('/repositories/{repository}/build-cache', UpdateBuildCacheController::class)->whereNumber('repository')->middleware(['can:update,repository', 'throttle:20,1'])->name('repositories.build-cache');
        Route::delete('/repositories/{repository}', DeleteRepositoryController::class)->whereNumber('repository')->middleware(['can:delete,repository', 'throttle:10,1'])->name('repositories.destroy');
        Route::post('/repositories/{repository}/webhook', UpdateRepositoryWebhookController::class)->whereNumber('repository')->middleware(['can:update,repository', 'throttle:10,1'])->name('repositories.webhook.store');
        Route::delete('/repositories/{repository}/webhook', UpdateRepositoryWebhookController::class)->whereNumber('repository')->middleware(['can:update,repository', 'throttle:10,1'])->name('repositories.webhook.destroy');
        Route::put('/repositories/{repository}/previews', UpdateRepositoryPreviewsController::class)->whereNumber('repository')->middleware(['can:update,repository', 'throttle:20,1'])->name('repositories.previews');
        Route::get('/previews', ShowPreviewsController::class)->name('previews');
        Route::post('/previews/branch', OpenBranchPreviewController::class)->middleware('throttle:10,1')->name('previews.branch');
        Route::post('/previews/{preview}/close', ClosePreviewController::class)->whereNumber('preview')->middleware(['can:operate,preview', 'throttle:20,1'])->name('previews.close');
        Route::post('/previews/{preview}/cleanup', RetryPreviewCleanupController::class)->whereNumber('preview')->middleware(['can:operate,preview', 'throttle:10,1'])->name('previews.cleanup');
        Route::post('/previews/{preview}/secrets', ApprovePreviewSecretsController::class)->whereNumber('preview')->middleware(['can:approveSecrets,preview', 'throttle:20,1'])->name('previews.secrets');
        Route::post('/repositories/{repository}/builds', StoreBuildController::class)->whereNumber('repository')->middleware(['can:deploy,repository', 'throttle:20,1'])->name('repositories.deploy');
        Route::post('/repositories/{repository}/scheduled-deploys', StoreScheduledDeployController::class)->whereNumber('repository')->middleware(['can:deploy,repository', 'throttle:20,1'])->name('repositories.scheduled-deploys.store');
        Route::delete('/repositories/{repository}/scheduled-deploys/{scheduled}', CancelScheduledDeployController::class)->whereNumber(['repository', 'scheduled'])->middleware(['can:deploy,repository', 'throttle:20,1'])->name('repositories.scheduled-deploys.destroy');
        Route::get('/environments', ShowDeployEnvironmentsController::class)->name('environments');
        Route::get('/environments/{environment}', ShowDeployEnvironmentController::class)->name('environments.show');
        Route::middleware(['can:configureDeploy,environment', 'throttle:30,1'])->group(function (): void {
            Route::put('/environments/{environment}/settings', UpdateEnvironmentDeploySettingsController::class)->name('environments.settings');
            Route::put('/environments/{environment}/controls', UpdateDeploymentControlsController::class)->name('environments.controls');
            Route::put('/environments/{environment}/build-server', UpdateEnvironmentBuildServerController::class)->name('environments.build-server');
            Route::post('/environments/{environment}/freezes', StoreEnvironmentFreezeController::class)->name('environments.freezes.store');
            Route::post('/environments/{environment}/variables', StoreEnvironmentVariableController::class)->name('environments.variables.store');
            Route::post('/environments/{environment}/secret-syncs', StoreSecretSyncController::class)->middleware('throttle:10,1')->name('environments.secret-syncs.store');
            Route::match(['POST', 'DELETE'], '/environments/{environment}/secret-syncs/{sync}', UpdateSecretSyncController::class)->whereNumber('sync')->middleware('throttle:20,1')->name('environments.secret-syncs.update');
            Route::put('/environments/{environment}/variables', ReplaceEnvironmentVariablesController::class)->name('environments.variables.replace');
            Route::post('/environments/{environment}/variable-changes/{change}', DecideVariableChangeController::class)->whereNumber('change')->name('environments.variable-changes.decide');
            Route::post('/environments/{environment}/processes', StoreEnvironmentProcessController::class)->name('environments.processes.store');
            Route::post('/environments/{environment}/resources', StoreEnvironmentResourceController::class)->name('environments.resources.store');
            Route::post('/environments/{environment}/deployment-schedules', StoreDeploymentScheduleController::class)->name('environments.deployment-schedules.store');
            Route::post('/environments/{environment}/scaling-schedules', StoreScalingScheduleController::class)->name('environments.scaling-schedules.store');
            Route::post('/environments/{environment}/tasks', StoreScheduledTaskController::class)->name('environments.tasks.store');
            Route::post('/environments/{environment}/tasks/{task}/run', RunScheduledTaskController::class)->whereNumber('task')->name('environments.tasks.run');
            Route::get('/environments/{environment}/tasks/{task}/runs/{run}', ShowScheduledTaskRunController::class)->whereNumber(['task', 'run'])->name('environments.tasks.runs.show');
            Route::put('/environments/{environment}/hibernation', UpdateEnvironmentHibernationController::class)->name('environments.hibernation');
            Route::put('/environments/{environment}/maintenance', UpdateEnvironmentMaintenanceController::class)->name('environments.maintenance');
            Route::put('/environments/{environment}/notifications', UpdateEnvironmentDeployNotificationsController::class)->name('environments.notifications');
            Route::post('/environments/{environment}/runtime', UpdateEnvironmentRuntimeController::class)->name('environments.runtime');
            Route::post('/environments/{environment}/recipes', StoreEnvironmentRecipeController::class)->name('environments.recipes.store');
            Route::post('/environments/{environment}/recipes/run', RunEnvironmentRecipesController::class)->name('environments.recipes.run');
            Route::put('/environments/{environment}/recipes/settings', UpdateEnvironmentRecipeSettingsController::class)->name('environments.recipes.settings');
            Route::post('/environments/{environment}/recipes/{entry}/refresh', RefreshEnvironmentRecipeController::class)->whereNumber('entry')->name('environments.recipes.refresh');
            Route::post('/environments/{environment}/recipes/{entry}/move', MoveEnvironmentRecipeController::class)->whereNumber('entry')->name('environments.recipes.move');
            Route::delete('/environments/{environment}/{kind}/{setting}', DeleteEnvironmentSettingController::class)->whereIn('kind', ['variables', 'processes', 'resources', 'deployment-schedules', 'scaling-schedules', 'tasks', 'recipes', 'freezes'])->whereNumber('setting')->name('environments.settings.destroy');
        });
        Route::get('/configuration', ShowConfigurationController::class)->name('configuration');
        Route::middleware(['can:manageDeploy,project', 'throttle:30,1'])->group(function (): void {
            Route::post('/configuration/plan', PlanConfigurationController::class)->name('configuration.plan');
            Route::post('/configuration/workflow', ApplyWorkflowController::class)->name('configuration.workflow');
            Route::post('/configuration/reviews', StoreConfigurationReviewController::class)->name('configuration.reviews.store');
            Route::post('/configuration/reviews/{review}/apply', ApplyConfigurationReviewController::class)->whereNumber('review')->name('configuration.reviews.apply');
            Route::post('/configuration/applications/{application}/operations/{operation}/{action}', UpdateConfigurationOperationController::class)->whereNumber(['application', 'operation'])->whereIn('action', ['cancel', 'retry'])->name('configuration.operations');
        });
        Route::get('/configuration/reviews/{review}', ShowConfigurationReviewController::class)->whereNumber('review')->name('configuration.reviews.show');
        Route::get('/configuration/applications/{application}', ShowConfigurationApplicationController::class)->whereNumber('application')->name('configuration.applications.show');
        Route::get('/builds/{build}', ShowBuildController::class)->whereNumber('build')->middleware('can:view,build')->name('builds.show');
        Route::get('/builds/{build}/status', ShowBuildStatusController::class)->whereNumber('build')->middleware(['can:view,build', 'throttle:120,1'])->name('builds.status');
        Route::get('/builds/{build}/compare', ShowBuildComparisonController::class)->whereNumber('build')->middleware('can:view,build')->name('builds.compare');
        Route::post('/builds/{build}/redeploy', RedeployBuildController::class)->whereNumber('build')->middleware(['can:view,build', 'throttle:20,1'])->name('builds.redeploy');
        Route::post('/builds/{build}/rollback', RollbackBuildController::class)->whereNumber('build')->middleware(['can:view,build', 'throttle:20,1'])->name('builds.rollback');
        Route::post('/builds/{build}/promote', PromoteBuildController::class)->whereNumber('build')->middleware(['can:view,build', 'throttle:20,1'])->name('builds.promote');
        Route::post('/builds/{build}/cancel', CancelBuildController::class)->whereNumber('build')->middleware(['can:view,build', 'throttle:20,1'])->name('builds.cancel');
        Route::match(['PUT', 'DELETE'], '/environments/{environment}/release-notes', UpdateReleaseNotesPageController::class)->middleware('throttle:20,1')->name('environments.release-notes');
        Route::post('/builds/{build}/approve-migrations', ApproveMigrationsController::class)->whereNumber('build')->middleware(['can:approve,build', 'throttle:10,1'])->name('builds.approve-migrations');
        Route::get('/builds/{build}/decide', ShowDeployDecisionController::class)->whereNumber('build')->middleware('can:view,build')->name('builds.decide');
        Route::post('/builds/{build}/review', ReviewBuildController::class)->whereNumber('build')->middleware(['can:approve,build', 'throttle:20,1'])->name('builds.review');
    });
});
