<?php

use App\Actions\Server\CollectServerLogAction;
use App\Http\Controllers\AccountDataController;
use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AdminAccessRequestController;
use App\Http\Controllers\AdminAnalyticsController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BuildRevisionCallbackController;
use App\Http\Controllers\BuildsController;
use App\Http\Controllers\CommandsController;
use App\Http\Controllers\CostController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\EnterpriseSsoController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\GitHubAppController;
use App\Http\Controllers\ImportServerController;
use App\Http\Controllers\ImportWebsiteController;
use App\Http\Controllers\LoadBalancerController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\ObservabilityController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PlatformStatusController;
use App\Http\Controllers\ProductFeedbackController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProviderConnectionController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ProviderServerCatalogController;
use App\Http\Controllers\PublicStatusPageController;
use App\Http\Controllers\RecipeFavoritesController;
use App\Http\Controllers\RecipeGalleryController;
use App\Http\Controllers\RecipeRatingsController;
use App\Http\Controllers\RecipeReportsController;
use App\Http\Controllers\RecipesController;
use App\Http\Controllers\RepositoriesController;
use App\Http\Controllers\RepositoryWebhookSettingsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServerCommandsController;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\SignInHistoryController;
use App\Http\Controllers\StatusSubscriptionController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\TwoFactorAuthenticationController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebsitesController;
use App\Http\Livewire\ServerShow;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Build;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogSnapshot;
use App\Services\AutomaticDeploymentRollback;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\RepositoryDeploymentPlan;
use App\Services\ServerProvisioningPlan;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::permanentRedirect('favicon.ico', '/favicon.svg');
Route::get('pricing', fn () => view('scenes.pricing', [
    'plans' => config('billing.plans'),
    'registrationOpen' => app(\App\Services\RegistrationAccess::class)->allowsNewUser(),
]))->name('pricing');
Route::get('request-access', [AccessRequestController::class, 'create'])->name('access-request.create');
Route::post('request-access', [AccessRequestController::class, 'store'])->middleware('throttle:access-requests')->name('access-request.store');
Route::view('privacy', 'legal.privacy')->name('privacy');
Route::view('terms', 'legal.terms')->name('terms');
Route::view('api-docs', 'api-docs')->name('api-docs');
Route::view('docs', 'docs')->name('docs');
Route::get('openapi.json', fn () => response()->file(public_path('openapi.json'), [
    'Cache-Control' => 'public, max-age=300',
    'Content-Type' => 'application/json',
]))->name('openapi');
Route::middleware([])->withoutMiddleware([
    VerifyCsrfToken::class,
    StartSession::class,
    ShareErrorsFromSession::class,
])->group(function (): void {
    Route::get('status', [PlatformStatusController::class, 'show'])->name('platform-status.show');
    Route::get('status/report.json', [PlatformStatusController::class, 'report'])->name('platform-status.report');
});
Route::get('status/{slug}', [PublicStatusPageController::class, 'show'])->name('status.show');
Route::get('status/{slug}/report.json', [PublicStatusPageController::class, 'report'])->name('status.report');
Route::post('status/{slug}/subscribe', [StatusSubscriptionController::class, 'store'])->middleware('throttle:5,1')->name('status.subscriptions.store');
Route::get('status/subscriptions/{subscription}/confirm/{token}', [StatusSubscriptionController::class, 'confirm'])->middleware('throttle:20,1')->name('status.subscriptions.confirm');
Route::get('status/subscriptions/{subscription}/unsubscribe/{token}', [StatusSubscriptionController::class, 'unsubscribe'])->middleware('throttle:20,1')->name('status.subscriptions.unsubscribe');

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return view('scenes.index');
});

Route::middleware('auth')->group(function () {
    Route::get('organization', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::get('github-app/connect', [GitHubAppController::class, 'connect'])->name('github-app.connect');
    Route::get('github-app/callback', [GitHubAppController::class, 'callback'])->name('github-app.callback');
    Route::get('github-app/providers/{provider}/repositories', [GitHubAppController::class, 'repositories'])->name('github-app.repositories');
    Route::patch('organization/notification-preferences', [OrganizationController::class, 'updateNotificationPreferences'])->name('organizations.notification-preferences.update');
    Route::patch('organization/security-policy', [OrganizationController::class, 'updateSecurityPolicy'])->name('organizations.security-policy.update');
    Route::get('organization/sso/connect', [EnterpriseSsoController::class, 'connect'])->name('organizations.sso.connect');
    Route::get('organization/sso/callback', [EnterpriseSsoController::class, 'callback'])->name('organizations.sso.callback');
    Route::post('organization/invitations', [OrganizationController::class, 'invite'])->name('organizations.invitations.store');
    Route::get('organization/invitations/{invitation}/accept', [OrganizationController::class, 'accept'])->name('organizations.invitations.accept');
    Route::post('organization/{organization}/switch', [OrganizationController::class, 'switch'])->name('organizations.switch');
    Route::patch('organization/members/{member}', [OrganizationController::class, 'updateMember'])->name('organizations.members.update');
    Route::delete('organization/members/{member}', [OrganizationController::class, 'removeMember'])->name('organizations.members.destroy');
    Route::delete('organization/{organization}', [OrganizationController::class, 'destroy'])
        ->middleware('throttle:sensitive-account')
        ->name('organizations.destroy');
    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::post('billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
    Route::get('account', [UsersController::class, 'index'])->name('account.index');
    Route::get('account/export', [AccountDataController::class, 'export'])
        ->middleware('throttle:sensitive-account')
        ->name('account.export');
    Route::delete('account', AccountDeletionController::class)
        ->middleware('throttle:sensitive-account')
        ->name('account.destroy');
    Route::patch('account/profile', [UsersController::class, 'updateProfile'])
        ->middleware('throttle:sensitive-account')
        ->name('account.profile.update');
    Route::patch('account/password', [UsersController::class, 'updatePassword'])
        ->middleware('throttle:sensitive-account')
        ->name('account.password.update');
    Route::post('account/two-factor', [TwoFactorAuthenticationController::class, 'enable'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.enable');
    Route::post('account/two-factor/confirm', [TwoFactorAuthenticationController::class, 'confirm'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.confirm');
    Route::delete('account/two-factor/setup', [TwoFactorAuthenticationController::class, 'cancel'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.cancel');
    Route::delete('account/two-factor', [TwoFactorAuthenticationController::class, 'disable'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.disable');
    Route::post('account/two-factor/recovery-codes', [TwoFactorAuthenticationController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.recovery-codes');
    Route::post('account/sessions/revoke', [UsersController::class, 'revokeOtherSessions'])
        ->middleware('throttle:sensitive-account')
        ->name('account.sessions.revoke');
    Route::delete('account/sessions/{session}', [UsersController::class, 'revokeSession'])
        ->where('session', '[A-Za-z0-9]{1,255}')
        ->middleware('throttle:sensitive-account')
        ->name('account.sessions.destroy');
    Route::get('account/sign-ins', [SignInHistoryController::class, 'index'])
        ->name('account.sign-ins.index');
    Route::get('account/sign-ins/export', [SignInHistoryController::class, 'export'])
        ->name('account.sign-ins.export');
    Route::delete('account/sign-ins', [SignInHistoryController::class, 'destroy'])
        ->middleware('throttle:sensitive-account')
        ->name('account.sign-ins.destroy');
    Route::delete('account/social/{provider}', [UsersController::class, 'disconnectSocial'])
        ->whereIn('provider', array_keys(User::SOCIAL_PROVIDER_COLUMNS))
        ->middleware('throttle:sensitive-account')
        ->name('account.social.destroy');
    Route::get('account/social/{provider}/connect', [SocialAuthController::class, 'connect'])
        ->whereIn('provider', SocialAuthController::providers())
        ->middleware('password.confirm.local')
        ->name('account.social.connect');

    Route::middleware('verified')->group(function () {
        Route::resource('projects', ProjectController::class)->except(['edit', 'update']);
        Route::patch('projects/{project}/previews', [ProjectController::class, 'updatePreviews'])
            ->name('projects.previews.update');
        Route::post('projects/{project}/environments', [EnvironmentController::class, 'store'])->name('environments.store');
        Route::patch('environments/{environment}', [EnvironmentController::class, 'update'])->name('environments.update');
        Route::delete('environments/{environment}', [EnvironmentController::class, 'destroy'])->name('environments.destroy');
        Route::post('environments/{environment}/variables', [EnvironmentController::class, 'variables'])->name('environments.variables.store');
        Route::delete('environments/{environment}/variables/{variable}', [EnvironmentController::class, 'destroyVariable'])->name('environments.variables.destroy');
        Route::post('environments/{environment}/processes', [EnvironmentController::class, 'storeProcess'])->name('environments.processes.store');
        Route::delete('environments/{environment}/processes/{process}', [EnvironmentController::class, 'destroyProcess'])->name('environments.processes.destroy');
        Route::post('environments/{environment}/resources', [EnvironmentController::class, 'storeResource'])->name('environments.resources.store');
        Route::patch('environments/{environment}/deployment-controls', [EnvironmentController::class, 'updateDeploymentControls'])->name('environments.deployment-controls.update');
        Route::delete('environments/{environment}/resources/{resource}', [EnvironmentController::class, 'destroyResource'])->name('environments.resources.destroy');
        Route::get('home', DashboardController::class)->name('dashboard');
        Route::get('admin/analytics', AdminAnalyticsController::class)
            ->middleware('throttle:30,1')
            ->name('admin.analytics');
        Route::get('admin/access-requests', [AdminAccessRequestController::class, 'index'])->middleware('throttle:30,1')->name('admin.access-requests.index');
        Route::get('admin/access-requests/export', [AdminAccessRequestController::class, 'export'])->middleware('throttle:10,1')->name('admin.access-requests.export');
        Route::patch('admin/access-requests/{accessRequest}', [AdminAccessRequestController::class, 'update'])->middleware('throttle:30,1')->name('admin.access-requests.update');
        Route::patch('home/preferences', [DashboardController::class, 'updatePreferences'])->name('dashboard.preferences.update');
        Route::get('system-health/report', [SystemHealthController::class, 'report'])->name('system-health.report');
        Route::get('system-health', SystemHealthController::class)->name('system-health.index');
        Route::get('observability', [ObservabilityController::class, 'index'])->name('observability.index');
        Route::get('observability/operational-incidents/export', [\App\Http\Controllers\OperationalIncidentController::class, 'export'])->name('observability.operational-incidents.export');
        Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
        Route::get('databases', [DatabaseController::class, 'index'])->name('databases.index');
        Route::get('costs', [CostController::class, 'index'])->name('costs.index');
        Route::patch('costs/budget', [CostController::class, 'update'])->name('costs.update');
        Route::get('feedback', [ProductFeedbackController::class, 'index'])->name('feedback.index');
        Route::post('feedback', [ProductFeedbackController::class, 'store'])->middleware('throttle:10,60')->name('feedback.store');
        Route::patch('feedback/{feedback}', [ProductFeedbackController::class, 'update'])->name('feedback.update');
        Route::delete('feedback/{feedback}', [ProductFeedbackController::class, 'destroy'])->name('feedback.destroy');
        Route::get('load-balancers', [LoadBalancerController::class, 'index'])->name('load-balancers.index');
        Route::post('load-balancers', [LoadBalancerController::class, 'store'])->name('load-balancers.store');
        Route::post('load-balancers/{loadBalancer}/apply', [LoadBalancerController::class, 'apply'])->name('load-balancers.apply');
        Route::delete('load-balancers/{loadBalancer}', [LoadBalancerController::class, 'destroy'])->name('load-balancers.destroy');
        Route::post('load-balancers/{loadBalancer}/nodes', [LoadBalancerController::class, 'storeNode'])->name('load-balancers.nodes.store');
        Route::delete('load-balancer-nodes/{node}', [LoadBalancerController::class, 'destroyNode'])->name('load-balancers.nodes.destroy');
        Route::post('databases/{resource}/inspect', [DatabaseController::class, 'inspect'])->name('databases.inspect');
        Route::post('databases/{resource}/users', [DatabaseController::class, 'storeUser'])->name('databases.users.store');
        Route::delete('databases/users/{databaseUser}', [DatabaseController::class, 'destroyUser'])->name('databases.users.destroy');
        Route::post('databases/{resource}/clone', [DatabaseController::class, 'clone'])->name('databases.clone');
        Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
        Route::post('domains/temporary', [DomainController::class, 'temporary'])->name('domains.temporary');
        Route::post('domains/{domain}/sync', [DomainController::class, 'sync'])->name('domains.sync');
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
        Route::get('automation', [AutomationController::class, 'index'])->name('automation.index');
        Route::put('automation/projects/{project}/workflow', [AutomationController::class, 'workflow'])->name('automation.workflow');
        Route::post('automation/environments/{environment}/deployment-schedules', [AutomationController::class, 'deploymentSchedule'])->name('automation.deployment-schedules.store');
        Route::delete('automation/deployment-schedules/{schedule}', [AutomationController::class, 'destroyDeploymentSchedule'])->name('automation.deployment-schedules.destroy');
        Route::post('automation/environments/{environment}/scaling-schedules', [AutomationController::class, 'scalingSchedule'])->name('automation.scaling-schedules.store');
        Route::delete('automation/scaling-schedules/{schedule}', [AutomationController::class, 'destroyScalingSchedule'])->name('automation.scaling-schedules.destroy');
        Route::patch('automation/environments/{environment}/scale', [AutomationController::class, 'scale'])->name('automation.scale');
        Route::patch('automation/environments/{environment}/runtime', [AutomationController::class, 'runtime'])->name('automation.runtime');
        Route::post('automation/environments/{environment}/tasks', [AutomationController::class, 'scheduledTask'])->name('automation.tasks.store');
        Route::post('automation/tasks/{task}/run', [AutomationController::class, 'runScheduledTask'])->name('automation.tasks.run');
        Route::delete('automation/tasks/{task}', [AutomationController::class, 'destroyScheduledTask'])->name('automation.tasks.destroy');
        Route::get('automation/task-runs/{run}/output', [AutomationController::class, 'scheduledTaskOutput'])->name('automation.task-runs.output');
        Route::post('automation/tokens', [AutomationController::class, 'token'])->name('automation.tokens.store');
        Route::post('automation/tokens/{token}/rotate', [AutomationController::class, 'rotateToken'])->name('automation.tokens.rotate');
        Route::delete('automation/tokens/{token}', [AutomationController::class, 'destroyToken'])->name('automation.tokens.destroy');
        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups/destinations', [BackupController::class, 'storeDestination'])->name('backups.destinations.store');
        Route::delete('backups/destinations/{destination}', [BackupController::class, 'destroyDestination'])->name('backups.destinations.destroy');
        Route::post('backups/schedules', [BackupController::class, 'storeSchedule'])->name('backups.schedules.store');
        Route::delete('backups/schedules/{schedule}', [BackupController::class, 'destroySchedule'])->name('backups.schedules.destroy');
        Route::post('backups/websites/{website}/run', [BackupController::class, 'run'])->name('backups.run');
        Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
        Route::post('observability/destinations', [ObservabilityController::class, 'storeDestination'])->name('observability.destinations.store');
        Route::post('observability/metric-rules', [ObservabilityController::class, 'storeMetricRule'])->name('observability.metric-rules.store');
        Route::delete('observability/metric-rules/{rule}', [ObservabilityController::class, 'destroyMetricRule'])->name('observability.metric-rules.destroy');
        Route::post('observability/destinations/{destination}/test', [ObservabilityController::class, 'testDestination'])->name('observability.destinations.test');
        Route::delete('observability/destinations/{destination}', [ObservabilityController::class, 'destroyDestination'])->name('observability.destinations.destroy');
        Route::post('observability/status-pages', [ObservabilityController::class, 'storeStatusPage'])->name('observability.status-pages.store');
        Route::patch('observability/status-pages/{statusPage}', [ObservabilityController::class, 'updateStatusPage'])->name('observability.status-pages.update');
        Route::delete('observability/status-pages/{statusPage}', [ObservabilityController::class, 'destroyStatusPage'])->name('observability.status-pages.destroy');
        Route::post('observability/incidents', [ObservabilityController::class, 'storeIncident'])->name('observability.incidents.store');
        Route::patch('observability/incidents/{incident}', [ObservabilityController::class, 'updateIncident'])->name('observability.incidents.update');
        Route::post('observability/operational-incidents/{incident}/acknowledge', [\App\Http\Controllers\OperationalIncidentController::class, 'acknowledge'])->name('observability.operational-incidents.acknowledge');
        Route::patch('observability/operational-incidents/{incident}/assign', [\App\Http\Controllers\OperationalIncidentController::class, 'assign'])->name('observability.operational-incidents.assign');
        Route::post('observability/operational-incidents/{incident}/notes', [\App\Http\Controllers\OperationalIncidentController::class, 'note'])->name('observability.operational-incidents.notes.store');
        Route::post('observability/operational-incidents/{incident}/resolve', [\App\Http\Controllers\OperationalIncidentController::class, 'resolve'])->name('observability.operational-incidents.resolve');
        Route::get('search', SearchController::class)->name('search.index');
        Route::get('activity/export', [ActivityController::class, 'export'])
            ->name('activity.export');
        Route::get('activity', ActivityController::class)->name('activity.index');
        Route::get('commands/export', [CommandsController::class, 'export'])->name('commands.export');
        Route::get('commands', CommandsController::class)->name('commands.index');
        Route::get('notifications/export', [NotificationsController::class, 'export'])
            ->name('notifications.export');
        Route::get('notifications', [NotificationsController::class, 'index'])
            ->name('notifications.index');
        Route::post('notifications/saved-filters', [NotificationsController::class, 'saveFilter'])->name('notifications.saved-filters.store');
        Route::delete('notifications/saved-filters/{filter}', [NotificationsController::class, 'destroyFilter'])->name('notifications.saved-filters.destroy');
        Route::post('notifications/read-all', [NotificationsController::class, 'readAll'])
            ->name('notifications.read-all');
        Route::post('notifications/clear-read', [NotificationsController::class, 'clearRead'])
            ->name('notifications.clear-read');
        Route::patch('notifications/bulk', [NotificationsController::class, 'bulk'])
            ->name('notifications.bulk');
        Route::post('notifications/{notification}/read', [NotificationsController::class, 'read'])
            ->name('notifications.read');
        Route::post('notifications/{notification}/unread', [NotificationsController::class, 'unread'])
            ->name('notifications.unread');
        Route::delete('notifications/{notification}', [NotificationsController::class, 'destroy'])
            ->name('notifications.destroy');

        Route::get('websites/export', [WebsitesController::class, 'export'])
            ->name('websites.export');
        Route::get('websites/import', [ImportWebsiteController::class, 'create'])->name('websites.import.create');
        Route::post('websites/import', [ImportWebsiteController::class, 'store'])->name('websites.import.store');
        Route::get('websites/{website}/health-checks', [WebsitesController::class, 'healthChecks'])
            ->name('websites.health-checks.index');
        Route::get('websites/{website}/health-checks/export', [WebsitesController::class, 'exportHealthChecks'])
            ->name('websites.health-checks.export');
        Route::resource('websites', WebsitesController::class);
        Route::post('websites/{website}/placement/cleanup', [WebsitesController::class, 'retryPlacementCleanup'])
            ->name('websites.placement.cleanup');
        Route::post('websites/{website}/provisioning/retry', [WebsitesController::class, 'retryProvisioning'])
            ->name('websites.provisioning.retry');
        Route::post('websites/{website}/health/check', [WebsitesController::class, 'checkHealth'])
            ->name('websites.health.check');
        Route::post('websites/{website}/logs/{type}/refresh', [WebsitesController::class, 'refreshRuntimeLog'])
            ->whereIn('type', WebsiteLogSnapshot::TYPES)
            ->name('websites.runtime-logs.refresh');
        Route::get('websites/{website}/logs/{type}', [WebsitesController::class, 'runtimeLog'])
            ->whereIn('type', WebsiteLogSnapshot::TYPES)
            ->name('websites.runtime-logs.show');
        Route::patch('websites/{website}/logs/retention', [WebsitesController::class, 'updateLogRetention'])
            ->name('websites.runtime-logs.retention');
        Route::get('websites/{website}/provisioning-log', [WebsitesController::class, 'downloadProvisioningLog'])
            ->name('websites.provisioning-log.download');

        Route::get('servers/export', [ServersController::class, 'export'])
            ->name('servers.export');
        Route::get('servers/import', [ImportServerController::class, 'create'])->name('servers.import.create');
        Route::post('servers/import', [ImportServerController::class, 'store'])->name('servers.import.store');
        Route::get('servers/import/{assessment}/review', [ImportServerController::class, 'review'])->name('servers.import.review');
        Route::post('servers/import/{assessment}/confirm', [ImportServerController::class, 'confirm'])->middleware('throttle:6,1')->name('servers.import.confirm');
        Route::resource('servers', ServersController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
        Route::get('servers/{server}', ServerShow::class)
            ->middleware('can:view,server')
            ->name('servers.show');
        Route::get('servers/{server}/logs/{type}', [ServersController::class, 'downloadLog'])
            ->whereIn('type', CollectServerLogAction::TYPES)
            ->name('servers.logs.download');
        Route::get('servers/{server}/commands', [ServerCommandsController::class, 'index'])
            ->name('servers.commands.index');
        Route::get('servers/{server}/commands/export', [ServerCommandsController::class, 'export'])
            ->name('servers.commands.export');
        Route::post('servers/{server}/commands/{execution}/cancel', [ServerCommandsController::class, 'cancel'])
            ->whereNumber('execution')
            ->name('servers.commands.cancel');
        Route::post('servers/{server}/commands/{execution}/rerun', [ServerCommandsController::class, 'rerun'])
            ->whereNumber('execution')
            ->name('servers.commands.rerun');
        Route::delete('servers/{server}/commands/{execution}', [ServerCommandsController::class, 'destroy'])
            ->whereNumber('execution')
            ->name('servers.commands.destroy');
        Route::get('servers/{server}/commands/{execution}/output', [ServerCommandsController::class, 'downloadOutput'])
            ->whereNumber('execution')
            ->name('servers.commands.output');
        Route::post('servers/{server}/initialization/retry', [ServersController::class, 'retryInitialization'])
            ->name('servers.initialization.retry');
        Route::post('servers/{server}/provisioning/retry', [ServersController::class, 'retryRemoteProvisioning'])
            ->name('servers.provisioning.retry');

        Route::get('builds/export', [BuildsController::class, 'export'])
            ->name('builds.export');
        Route::get('builds/{build}/log', [BuildsController::class, 'downloadLog'])
            ->name('builds.log.download');
        Route::get('builds/{build}/compare/{baseline}', [BuildsController::class, 'compare'])
            ->whereNumber(['build', 'baseline'])
            ->name('builds.compare');
        Route::resource('builds', BuildsController::class)->only(['index', 'show']);
        Route::post('builds/{build}/cancel', [BuildsController::class, 'cancel'])
            ->name('builds.cancel');
        Route::post('builds/{build}/redeploy', [BuildsController::class, 'redeploy'])
            ->name('builds.redeploy');
        Route::post('builds/{build}/approve', [BuildsController::class, 'approve'])
            ->name('builds.approve');
        Route::post('builds/{build}/promote', \App\Http\Controllers\BuildPromotionController::class)->name('builds.promote');
        Route::post('builds/{build}/reject', [BuildsController::class, 'reject'])
            ->name('builds.reject');
        Route::post('builds/{build}/rollback', [BuildsController::class, 'rollback'])
            ->name('builds.rollback');
        Route::patch('builds/{build}/note', [BuildsController::class, 'updateNote'])
            ->name('builds.note.update');
        Route::get('repositories/export', [RepositoriesController::class, 'export'])
            ->name('repositories.export');
        Route::get('repositories/{repository}/webhook-deliveries/export', [RepositoriesController::class, 'exportWebhookDeliveries'])
            ->name('repositories.webhook-deliveries.export');
        Route::resource('repositories', RepositoriesController::class);
        Route::get('recipes/export', [RecipesController::class, 'export'])
            ->name('recipes.export');
        Route::get('gallery', [RecipeGalleryController::class, 'index'])
            ->name('gallery.index');
        Route::get('gallery/reports/inbox', [RecipeReportsController::class, 'index'])
            ->name('gallery.reports.index');
        Route::get('gallery/my-reports', [RecipeReportsController::class, 'mine'])
            ->name('gallery.reports.mine');
        Route::post('gallery/my-reports/review-updates', [RecipeReportsController::class, 'reviewUpdates'])
            ->middleware('throttle:10,1')
            ->name('gallery.reports.mine.review-updates');
        Route::get('gallery/my-reports/export', [RecipeReportsController::class, 'exportMine'])
            ->name('gallery.reports.mine.export');
        Route::get('gallery/reports/{report}/status', [RecipeReportsController::class, 'status'])
            ->whereNumber('report')
            ->name('gallery.report.status');
        Route::get('gallery/reports/export', [RecipeReportsController::class, 'export'])
            ->name('gallery.reports.export');
        Route::patch('gallery/reports/resolve', [RecipeReportsController::class, 'resolveMany'])
            ->middleware('throttle:10,1')
            ->name('gallery.reports.resolve-many');
        Route::patch('gallery/reports/reopen', [RecipeReportsController::class, 'reopenMany'])
            ->middleware('throttle:10,1')
            ->name('gallery.reports.reopen-many');
        Route::get('gallery/{recipe}/compare/{copy}', [RecipeGalleryController::class, 'compare'])
            ->whereNumber(['recipe', 'copy'])
            ->name('gallery.compare');
        Route::get('gallery/{recipe}', [RecipeGalleryController::class, 'show'])
            ->whereNumber('recipe')
            ->name('gallery.show');
        Route::post('gallery/{recipe}/install', [RecipeGalleryController::class, 'install'])
            ->whereNumber('recipe')
            ->middleware('throttle:20,1')
            ->name('gallery.install');
        Route::post('gallery/{recipe}/favorite', [RecipeFavoritesController::class, 'store'])
            ->whereNumber('recipe')
            ->middleware('throttle:30,1')
            ->name('gallery.favorite.store');
        Route::delete('gallery/{recipe}/favorite', [RecipeFavoritesController::class, 'destroy'])
            ->whereNumber('recipe')
            ->middleware('throttle:30,1')
            ->name('gallery.favorite.destroy');
        Route::post('gallery/{recipe}/report', [RecipeReportsController::class, 'store'])
            ->whereNumber('recipe')
            ->middleware('throttle:10,1')
            ->name('gallery.report.store');
        Route::delete('gallery/{recipe}/report', [RecipeReportsController::class, 'destroy'])
            ->whereNumber('recipe')
            ->middleware('throttle:10,1')
            ->name('gallery.report.destroy');
        Route::patch('gallery/{recipe}/reports/{report}/resolve', [RecipeReportsController::class, 'resolve'])
            ->whereNumber(['recipe', 'report'])
            ->middleware('throttle:30,1')
            ->name('gallery.reports.resolve');
        Route::patch('gallery/{recipe}/reports/{report}/resolution-note', [RecipeReportsController::class, 'updateResolutionNote'])
            ->whereNumber(['recipe', 'report'])
            ->middleware('throttle:30,1')
            ->name('gallery.reports.resolution-note.update');
        Route::patch('gallery/{recipe}/reports/{report}/reopen', [RecipeReportsController::class, 'reopen'])
            ->whereNumber(['recipe', 'report'])
            ->middleware('throttle:30,1')
            ->name('gallery.reports.reopen');
        Route::post('gallery/{recipe}/rating', [RecipeRatingsController::class, 'store'])
            ->whereNumber('recipe')
            ->middleware('throttle:30,1')
            ->name('gallery.rating.store');
        Route::delete('gallery/{recipe}/rating', [RecipeRatingsController::class, 'destroy'])
            ->whereNumber('recipe')
            ->name('gallery.rating.destroy');
        Route::post('recipes/{recipe}/refresh-from-gallery', [RecipeGalleryController::class, 'refresh'])
            ->whereNumber('recipe')
            ->name('recipes.gallery.refresh');
        Route::resource('recipes', RecipesController::class);
        Route::post('recipes/{recipe}/duplicate', [RecipesController::class, 'duplicate'])
            ->name('recipes.duplicate');
        Route::get('providers/export', [ProviderController::class, 'export'])
            ->name('providers.export');
        Route::get('providers/{provider}/connection-checks', [ProviderController::class, 'connectionChecks'])
            ->name('providers.connection-checks.index');
        Route::get('providers/{provider}/connection-checks/export', [ProviderController::class, 'exportConnectionChecks'])
            ->name('providers.connection-checks.export');
        Route::get('providers/{provider}/server-catalog', ProviderServerCatalogController::class)
            ->middleware('throttle:12,1')
            ->name('providers.server-catalog');
        Route::resource('providers', ProviderController::class);
        Route::post('providers/{provider}/connection/test', ProviderConnectionController::class)
            ->middleware('throttle:6,1')
            ->name('providers.connection.test');
        Route::post('repositories/{repository}/deploy', [RepositoriesController::class, 'deploy'])
            ->name('repositories.deploy');
        Route::post('repositories/{repository}/webhook', [RepositoryWebhookSettingsController::class, 'store'])
            ->name('repositories.webhook.store');
        Route::delete('repositories/{repository}/webhook', [RepositoryWebhookSettingsController::class, 'destroy'])
            ->name('repositories.webhook.destroy');
    });
});

Route::post('servers/{server}/provisioning/callback/status', function (Server $server) {
    if ($server->provisioning_token && ! hash_equals($server->provisioning_token, (string) request('attempt'))) {
        return response()->noContent();
    }

    if (! in_array($server->provisioning_status, [
        Server::STATUS_QUEUED,
        Server::STATUS_WAITING_FOR_IP,
        Server::STATUS_PROVISIONING,
    ], true)) {
        return response()->noContent();
    }

    $finalStage = app(ServerProvisioningPlan::class)->finalStage($server);
    $data = request()->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
    if ($data['status'] > $server->setup_stage) {
        $server->update(['setup_stage' => $data['status']]);
    }

    if ($data['status'] === $finalStage) {
        $server->update([
            'provisioning_status' => Server::STATUS_ACTIVE,
            'password' => null,
            'provisioned_at' => now(),
            'provisioning_error' => null,
            'provisioning_failure_phase' => null,
            'provisioning_process_id' => null,
            'provisioning_process_path' => null,
            'initialization_token' => null,
        ]);
    }
})->middleware('signed')->name('callbacks.server');

Route::post('websites/{website}/provisioning/callback/status', function (Website $website) {
    if ($website->provisioning_token && ! hash_equals($website->provisioning_token, (string) request('attempt'))) {
        return response()->noContent();
    }

    if (! in_array($website->provisioning_status, [
        Website::STATUS_QUEUED,
        Website::STATUS_PROVISIONING,
    ], true)) {
        return response()->noContent();
    }

    $finalStage = app(WebsiteProvisioningPlan::class)->finalStage();
    $data = request()->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
    if ($data['status'] > $website->setup_stage) {
        $website->update(['setup_stage' => $data['status']]);
    }

    if ($data['status'] === $finalStage) {
        $previousServerId = $website->previous_server_id;
        $website->update([
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => now(),
            'provisioning_error' => null,
        ]);
        app(PreviewDeploymentLifecycle::class)->websiteReady($website->fresh());

        if ($previousServerId) {
            CleanupWebsitePlacementJob::dispatch(
                $website->id,
                $previousServerId,
                $website->deployment_slug,
            );
        }
    }
})->middleware('signed')->name('callbacks.website');

Route::post('servers/{server}/provisioning/callback/failed', function (Server $server) {
    if ($server->provisioning_token && ! hash_equals($server->provisioning_token, (string) request('attempt'))) {
        return response()->noContent();
    }

    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);
    if (in_array($server->provisioning_status, [
        Server::STATUS_QUEUED,
        Server::STATUS_WAITING_FOR_IP,
        Server::STATUS_PROVISIONING,
    ], true)) {
        $server->update([
            'password' => null,
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_error' => isset($data['exit_code'])
                ? "{$data['message']} (exit code {$data['exit_code']})"
                : $data['message'],
            'provisioning_failure_phase' => Server::FAILURE_REMOTE,
            'provisioning_process_id' => null,
            'provisioning_process_path' => null,
            'initialization_token' => null,
        ]);
        $server->logSnapshots()->updateOrCreate(
            ['type' => 'provisioning'],
            [
                'status' => ServerLogSnapshot::STATUS_FAILED,
                'error' => $server->provisioning_error,
                'refreshed_at' => now(),
            ],
        );
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.server.failed');

Route::post('servers/{server}/provisioning/callback/log', function (Server $server) {
    DB::transaction(function () use ($server): void {
        $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
        if ($locked->provisioning_token && ! hash_equals($locked->provisioning_token, (string) request('attempt'))) {
            return;
        }

        $data = request()->validate([
            'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.server_log_max_characters'))],
        ]);
        $locked->logSnapshots()->updateOrCreate(
            ['type' => 'provisioning'],
            [
                'status' => ServerLogSnapshot::STATUS_READY,
                'log' => $data['log'],
                'error' => null,
                'refreshed_at' => now(),
            ],
        );
    });

    return response()->noContent();
})->middleware('signed')->name('callbacks.server.log');

Route::post('websites/{website}/provisioning/callback/failed', function (Website $website) {
    if ($website->provisioning_token && ! hash_equals($website->provisioning_token, (string) request('attempt'))) {
        return response()->noContent();
    }

    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);
    if (in_array($website->provisioning_status, [
        Website::STATUS_QUEUED,
        Website::STATUS_PROVISIONING,
    ], true)) {
        $website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => isset($data['exit_code'])
                ? "{$data['message']} (exit code {$data['exit_code']})"
                : $data['message'],
        ]);
        app(PreviewDeploymentLifecycle::class)->websiteFailed($website->fresh());
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.website.failed');

Route::post('websites/{website}/provisioning/callback/log', function (Website $website) {
    DB::transaction(function () use ($website): void {
        $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
        if ($locked->provisioning_token && ! hash_equals($locked->provisioning_token, (string) request('attempt'))) {
            return;
        }

        $data = request()->validate([
            'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.website_log_max_characters'))],
        ]);
        $locked->logs()->updateOrCreate(
            ['type' => Website::PROVISIONING_LOG_TYPE],
            ['log' => $data['log']],
        );
    });

    return response()->noContent();
})->middleware('signed')->name('callbacks.website.log');

Route::post('builds/{build}/deployment/callback/status', function (Build $build) {
    $plan = app(RepositoryDeploymentPlan::class);
    $finalStage = $plan->finalStage();
    $activationStage = $plan->activationStage();
    $data = request()->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
    $finished = false;
    DB::transaction(function () use ($build, $data, $finalStage, $activationStage, &$finished): void {
        $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
        if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
            return;
        }

        $repository = $locked->repository;
        if ($data['status'] > $repository->setup_stage) {
            $repository->update(['setup_stage' => $data['status']]);
        }

        $attributes = ['last_heartbeat_at' => now()];
        if ($data['status'] > $locked->setup_stage) {
            $attributes['setup_stage'] = $data['status'];
        }
        if ($data['status'] >= $activationStage && $locked->activated_at === null) {
            $attributes['activated_at'] = now();
        }
        if ($data['status'] === $finalStage) {
            $finished = true;
            $attributes = array_merge($attributes, [
                'status' => Build::STATUS_SUCCEEDED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'built_at' => now(),
                'finished_at' => now(),
            ]);
        }
        $locked->update($attributes);
    });
    if ($finished) {
        app(PreviewDeploymentLifecycle::class)->buildFinished($build->fresh());
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.status');

Route::post('builds/{build}/deployment/callback/revision', BuildRevisionCallbackController::class)
    ->middleware('signed')
    ->name('callbacks.build.revision');

Route::post('builds/{build}/deployment/callback/failed', function (Build $build) {
    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);

    $finished = false;
    DB::transaction(function () use ($build, $data, &$finished): void {
        $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
        if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
            return;
        }

        $locked->update([
            'status' => Build::STATUS_FAILED,
            'remote_process_id' => null,
            'remote_process_path' => null,
            'finished_at' => now(),
            'failure_message' => isset($data['exit_code'])
                ? "{$data['message']} (exit code {$data['exit_code']})"
                : $data['message'],
        ]);
        $finished = true;
    });
    if ($finished) {
        app(PreviewDeploymentLifecycle::class)->buildFinished($build->fresh());
        app(AutomaticDeploymentRollback::class)->attempt($build->fresh());
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.failed');

Route::post('builds/{build}/deployment/callback/log', function (Build $build) {
    DB::transaction(function () use ($build): void {
        $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
        if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
            return;
        }

        $data = request()->validate([
            'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.deployment_log_max_characters'))],
        ]);

        $locked->logs()->updateOrCreate(
            ['type' => Build::DEPLOYMENT_LOG_TYPE],
            ['log' => $data['log']],
        );
        $locked->update(['last_heartbeat_at' => now()]);
    });

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.log');

require __DIR__.'/auth.php';
