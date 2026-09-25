<?php

use App\Core\Http\Controllers\Auth\PlatformSessionController;
use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Monitor\Http\Controllers\AlertDeliveryController;
use App\Modules\Monitor\Http\Controllers\AlertDestinationController;
use App\Modules\Monitor\Http\Controllers\AlertEscalationController;
use App\Modules\Monitor\Http\Controllers\AlertRoutingController;
use App\Modules\Monitor\Http\Controllers\AlertRuleController;
use App\Modules\Monitor\Http\Controllers\ApiDocumentationController;
use App\Modules\Monitor\Http\Controllers\ApplicationController;
use App\Modules\Monitor\Http\Controllers\AuditLogController;
use App\Modules\Monitor\Http\Controllers\Auth\EmailVerificationController;
use App\Modules\Monitor\Http\Controllers\Auth\PasswordResetController;
use App\Modules\Monitor\Http\Controllers\Auth\RegistrationController;
use App\Modules\Monitor\Http\Controllers\Auth\SessionController;
use App\Modules\Monitor\Http\Controllers\BillingController;
use App\Modules\Monitor\Http\Controllers\DashboardController;
use App\Modules\Monitor\Http\Controllers\DependencyMapController;
use App\Modules\Monitor\Http\Controllers\DeploymentController;
use App\Modules\Monitor\Http\Controllers\EnvironmentController;
use App\Modules\Monitor\Http\Controllers\EnvironmentIngestionController;
use App\Modules\Monitor\Http\Controllers\EventController;
use App\Modules\Monitor\Http\Controllers\HeartbeatTokenController;
use App\Modules\Monitor\Http\Controllers\IncidentController;
use App\Modules\Monitor\Http\Controllers\IngestTokenController;
use App\Modules\Monitor\Http\Controllers\IntegrationController;
use App\Modules\Monitor\Http\Controllers\IssueController;
use App\Modules\Monitor\Http\Controllers\MaintenanceWindowController;
use App\Modules\Monitor\Http\Controllers\MetricSeriesController;
use App\Modules\Monitor\Http\Controllers\MonitorController;
use App\Modules\Monitor\Http\Controllers\NotificationPreferenceController;
use App\Modules\Monitor\Http\Controllers\PublicStatusPageController;
use App\Modules\Monitor\Http\Controllers\QueueTokenController;
use App\Modules\Monitor\Http\Controllers\ReleaseController;
use App\Modules\Monitor\Http\Controllers\SavedDashboardController;
use App\Modules\Monitor\Http\Controllers\ServiceLevelObjectiveController;
use App\Modules\Monitor\Http\Controllers\StatusPageController;
use App\Modules\Monitor\Http\Controllers\TraceController;
use App\Modules\Monitor\Http\Controllers\TraceEventController;
use App\Modules\Monitor\Http\Controllers\WorkspaceController;
use App\Modules\Monitor\Http\Controllers\WorkspaceDataController;
use App\Modules\Monitor\Http\Controllers\WorkspaceInvitationController;
use App\Modules\Monitor\Http\Controllers\WorkspaceMemberController;
use App\Modules\Monitor\Http\Controllers\WorkspaceSearchController;
use Illuminate\Support\Facades\Route;

$monitorAuthentication = app(ProductAuthentication::class);
$guestMiddleware = $monitorAuthentication->guestMiddleware('monitor');
$authenticatedMiddleware = $monitorAuthentication->authenticatedMiddleware('monitor');
$logoutAction = $monitorAuthentication->usesCoreAuthority('monitor')
    ? [PlatformSessionController::class, 'destroy']
    : [SessionController::class, 'destroy'];

Route::middleware($guestMiddleware)->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:monitor.login')->name('login.store');
    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:monitor.registration')->name('register.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:monitor.account-email')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:monitor.account-email')->name('password.update');
});

Route::get('/status/{statusPage:slug}', [PublicStatusPageController::class, 'show'])->name('status-pages.public');

Route::middleware([...$authenticatedMiddleware, 'auth.session', 'monitor.account.active'])->group(function () use ($logoutAction): void {
    Route::post('/logout', $logoutAction)->name('logout');
    Route::get('/email/verify', [EmailVerificationController::class, 'show'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'update'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'store'])->middleware('throttle:monitor.account-email')->name('verification.send');

    Route::get('/workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:monitor.registration')->name('workspaces.store');
    Route::post('/workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->middleware('monitor.workspace')->name('workspaces.switch');

    Route::middleware('verified')->group(function (): void {
        Route::get('/invitations/{token}', [WorkspaceInvitationController::class, 'show'])->where('token', '[a-zA-Z0-9]{64}')->name('invitations.show');
        Route::post('/invitations/{token}', [WorkspaceInvitationController::class, 'update'])->where('token', '[a-zA-Z0-9]{64}')->name('invitations.accept');
        Route::post('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store'])->middleware(['monitor.workspace', 'throttle:monitor.account-email'])->name('invitations.store');
        Route::delete('/workspaces/{workspace}/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy'])->middleware('monitor.workspace')->name('invitations.destroy');
        Route::patch('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'update'])->middleware('monitor.workspace')->name('members.update');
        Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy'])->middleware('monitor.workspace')->name('members.destroy');
    });

    Route::middleware('monitor.workspace')->group(function (): void {
        Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class)
            ->whereNumber('workspace')
            ->middleware('throttle:60,1')
            ->name('workspace.search');

        Route::middleware('monitor.application.workspace')->scopeBindings()->group(function (): void {
            Route::resource('applications', ApplicationController::class)->except('show');
            Route::get('/applications/{application}', [ApplicationController::class, 'show'])->withTrashed()->name('applications.show');
            Route::post('/applications/{application}/restore', [ApplicationController::class, 'restore'])->withTrashed()->name('applications.restore');
            Route::post('/applications/{application}/environments', [EnvironmentController::class, 'store'])->name('environments.store');
            Route::get('/applications/{application}/environments/{environment}/deployments', [DeploymentController::class, 'index'])->name('deployments.index');
            Route::get('/applications/{application}/environments/{environment}/deployments/create', [DeploymentController::class, 'create'])->name('deployments.create');
            Route::post('/applications/{application}/environments/{environment}/deployments', [DeploymentController::class, 'store'])->middleware('throttle:60,1')->name('deployments.store');
            Route::get('/applications/{application}/environments/{environment}/deployments/{deployment}', [DeploymentController::class, 'show'])->whereNumber('deployment')->name('deployments.show');
            Route::get('/applications/{application}/environments/{environment}', [EnvironmentController::class, 'show'])->withTrashed()->name('environments.show');
            Route::patch('/applications/{application}/environments/{environment}', [EnvironmentController::class, 'update'])->name('environments.update');
            Route::delete('/applications/{application}/environments/{environment}', [EnvironmentController::class, 'destroy'])->name('environments.destroy');
            Route::post('/applications/{application}/environments/{environment}/restore', [EnvironmentController::class, 'restore'])->withTrashed()->name('environments.restore');
            Route::get('/applications/{application}/environments/{environment}/connection', [EnvironmentController::class, 'connection'])->middleware('throttle:60,1')->name('environments.connection');
            Route::get('/applications/{application}/environments/{environment}/ingestion', [EnvironmentIngestionController::class, 'index'])->withTrashed()->name('environments.ingestion');
            Route::post('/applications/{application}/environments/{environment}/ingest-receipts/{ingestReceipt}/retry', [EnvironmentIngestionController::class, 'store'])
                ->middleware('throttle:10,1')->name('environments.ingestion.retry');
            Route::post('/applications/{application}/environments/{environment}/tokens', [IngestTokenController::class, 'store'])->middleware('throttle:30,1')->name('ingest-tokens.store');
            Route::post('/applications/{application}/environments/{environment}/tokens/{ingestToken}/rotate', [IngestTokenController::class, 'rotate'])->middleware('throttle:30,1')->name('ingest-tokens.rotate');
            Route::delete('/applications/{application}/environments/{environment}/tokens/{ingestToken}', [IngestTokenController::class, 'destroy'])->name('ingest-tokens.destroy');
        });
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboards', [SavedDashboardController::class, 'index'])->name('dashboards.index');
        Route::get('/dashboards/{dashboard}', [SavedDashboardController::class, 'show'])->whereNumber('dashboard')->name('dashboards.show');
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        Route::get('/metrics', [MetricSeriesController::class, 'index'])->name('metrics.index');
        Route::get('/metrics/{metricSeries}', [MetricSeriesController::class, 'show'])->whereNumber('metricSeries')->name('metrics.show');
        Route::get('/dependencies', [DependencyMapController::class, 'index'])->name('dependencies.index');
        Route::get('/objectives', [ServiceLevelObjectiveController::class, 'index'])->name('objectives.index');
        Route::get('/events/{event}', [EventController::class, 'show'])->whereNumber('event')->name('events.show');
        Route::get('/issues', [IssueController::class, 'index'])->name('issues.index');
        Route::get('/alerts', [AlertRuleController::class, 'index'])->name('alerts.index');
        Route::get('/monitors', [MonitorController::class, 'index'])->name('monitors.index');
        Route::get('/status-pages', [StatusPageController::class, 'index'])->name('status-pages.index');
        Route::get('/maintenance-windows', [MaintenanceWindowController::class, 'index'])->name('maintenance-windows.index');
        Route::middleware('verified')->group(function (): void {
            Route::get('/dashboards/create', [SavedDashboardController::class, 'create'])->name('dashboards.create');
            Route::post('/dashboards', [SavedDashboardController::class, 'store'])->middleware('throttle:30,1')->name('dashboards.store');
            Route::get('/dashboards/{dashboard}/edit', [SavedDashboardController::class, 'edit'])->whereNumber('dashboard')->name('dashboards.edit');
            Route::patch('/dashboards/{dashboard}', [SavedDashboardController::class, 'update'])->whereNumber('dashboard')->middleware('throttle:30,1')->name('dashboards.update');
            Route::delete('/dashboards/{dashboard}', [SavedDashboardController::class, 'destroy'])->whereNumber('dashboard')->middleware('throttle:30,1')->name('dashboards.destroy');
            Route::get('/objectives/create', [ServiceLevelObjectiveController::class, 'create'])->name('objectives.create');
            Route::post('/objectives', [ServiceLevelObjectiveController::class, 'store'])->middleware('throttle:30,1')->name('objectives.store');
            Route::get('/objectives/{serviceLevelObjective}/export', [ServiceLevelObjectiveController::class, 'export'])->whereNumber('serviceLevelObjective')->middleware('throttle:30,1')->name('objectives.export');
            Route::get('/objectives/{serviceLevelObjective}/edit', [ServiceLevelObjectiveController::class, 'edit'])->whereNumber('serviceLevelObjective')->name('objectives.edit');
            Route::patch('/objectives/{serviceLevelObjective}', [ServiceLevelObjectiveController::class, 'update'])->whereNumber('serviceLevelObjective')->middleware('throttle:30,1')->name('objectives.update');
            Route::delete('/objectives/{serviceLevelObjective}', [ServiceLevelObjectiveController::class, 'destroy'])->whereNumber('serviceLevelObjective')->middleware('throttle:30,1')->name('objectives.destroy');
            Route::get('/monitors/create', [MonitorController::class, 'create'])->name('monitors.create');
            Route::post('/monitors', [MonitorController::class, 'store'])->middleware('throttle:30,1')->name('monitors.store');
            Route::post('/monitors/{monitor}/queue-key', [QueueTokenController::class, 'store'])->whereNumber('monitor')->middleware('throttle:10,1')->name('monitors.queue-key.store');
            Route::delete('/monitors/{monitor}/queue-key', [QueueTokenController::class, 'destroy'])->whereNumber('monitor')->middleware('throttle:10,1')->name('monitors.queue-key.destroy');
            Route::post('/monitors/{monitor}/heartbeat-key', [HeartbeatTokenController::class, 'store'])->whereNumber('monitor')->middleware('throttle:10,1')->name('monitors.heartbeat-key.store');
            Route::delete('/monitors/{monitor}/heartbeat-key', [HeartbeatTokenController::class, 'destroy'])->whereNumber('monitor')->middleware('throttle:10,1')->name('monitors.heartbeat-key.destroy');
            Route::get('/monitors/{monitor}/edit', [MonitorController::class, 'edit'])->whereNumber('monitor')->name('monitors.edit');
            Route::patch('/monitors/{monitor}', [MonitorController::class, 'update'])->whereNumber('monitor')->middleware('throttle:30,1')->name('monitors.update');
            Route::delete('/monitors/{monitor}', [MonitorController::class, 'destroy'])->whereNumber('monitor')->middleware('throttle:30,1')->name('monitors.destroy');
            Route::get('/status-pages/create', [StatusPageController::class, 'create'])->name('status-pages.create');
            Route::post('/status-pages', [StatusPageController::class, 'store'])->middleware('throttle:30,1')->name('status-pages.store');
            Route::get('/status-pages/{statusPage}/edit', [StatusPageController::class, 'edit'])->whereNumber('statusPage')->name('status-pages.edit');
            Route::patch('/status-pages/{statusPage}', [StatusPageController::class, 'update'])->whereNumber('statusPage')->middleware('throttle:30,1')->name('status-pages.update');
            Route::delete('/status-pages/{statusPage}', [StatusPageController::class, 'destroy'])->whereNumber('statusPage')->middleware('throttle:30,1')->name('status-pages.destroy');
            Route::get('/maintenance-windows/create', [MaintenanceWindowController::class, 'create'])->name('maintenance-windows.create');
            Route::post('/maintenance-windows', [MaintenanceWindowController::class, 'store'])->middleware('throttle:30,1')->name('maintenance-windows.store');
            Route::get('/maintenance-windows/{maintenanceWindow}/edit', [MaintenanceWindowController::class, 'edit'])->whereNumber('maintenanceWindow')->name('maintenance-windows.edit');
            Route::patch('/maintenance-windows/{maintenanceWindow}', [MaintenanceWindowController::class, 'update'])->whereNumber('maintenanceWindow')->middleware('throttle:30,1')->name('maintenance-windows.update');
            Route::delete('/maintenance-windows/{maintenanceWindow}', [MaintenanceWindowController::class, 'destroy'])->whereNumber('maintenanceWindow')->middleware('throttle:30,1')->name('maintenance-windows.destroy');
            Route::get('/settings/alert-destinations', [AlertDestinationController::class, 'index'])->name('alert-destinations.index');
            Route::get('/settings/alert-destinations/create', [AlertDestinationController::class, 'create'])->name('alert-destinations.create');
            Route::post('/settings/alert-destinations', [AlertDestinationController::class, 'store'])->middleware('throttle:30,1')->name('alert-destinations.store');
            Route::get('/settings/alert-destinations/{alertDestination}', [AlertDestinationController::class, 'show'])->whereNumber('alertDestination')->withTrashed()->name('alert-destinations.show');
            Route::get('/settings/alert-destinations/{alertDestination}/edit', [AlertDestinationController::class, 'edit'])->whereNumber('alertDestination')->name('alert-destinations.edit');
            Route::patch('/settings/alert-destinations/{alertDestination}', [AlertDestinationController::class, 'update'])->whereNumber('alertDestination')->middleware('throttle:30,1')->name('alert-destinations.update');
            Route::delete('/settings/alert-destinations/{alertDestination}', [AlertDestinationController::class, 'destroy'])->whereNumber('alertDestination')->middleware('throttle:30,1')->name('alert-destinations.destroy');
            Route::post('/settings/alert-destinations/{alertDestination}/rotate', [AlertDestinationController::class, 'rotate'])->whereNumber('alertDestination')->middleware('throttle:10,1')->name('alert-destinations.rotate');
            Route::post('/settings/alert-destinations/{alertDestination}/test', [AlertDeliveryController::class, 'store'])->whereNumber('alertDestination')->middleware('throttle:3,1')->name('alert-destinations.test');
            Route::get('/alert-deliveries/{alertDelivery}', [AlertDeliveryController::class, 'show'])->whereUlid('alertDelivery')->name('alert-deliveries.show');
            Route::post('/alert-deliveries/{alertDelivery}/retry', [AlertDeliveryController::class, 'update'])->whereUlid('alertDelivery')->middleware('throttle:10,1')->name('alert-deliveries.retry');
            Route::put('/alerts/{alertRule}/destinations', [AlertRoutingController::class, 'update'])->whereNumber('alertRule')->middleware('throttle:30,1')->name('alerts.destinations');
            Route::put('/alerts/{alertRule}/escalations', [AlertEscalationController::class, 'update'])->whereNumber('alertRule')->middleware('throttle:30,1')->name('alerts.escalations');
        });
        Route::get('/alerts/create', [AlertRuleController::class, 'create'])->name('alerts.create');
        Route::get('/monitors/{monitor}', [MonitorController::class, 'show'])->whereNumber('monitor')->withTrashed()->name('monitors.show');
        Route::get('/objectives/{serviceLevelObjective}', [ServiceLevelObjectiveController::class, 'show'])->whereNumber('serviceLevelObjective')->name('objectives.show');
        Route::post('/alerts', [AlertRuleController::class, 'store'])->middleware('throttle:60,1')->name('alerts.store');
        Route::get('/alerts/{alertRule}', [AlertRuleController::class, 'show'])->whereNumber('alertRule')->withTrashed()->name('alerts.show');
        Route::get('/alerts/{alertRule}/edit', [AlertRuleController::class, 'edit'])->whereNumber('alertRule')->name('alerts.edit');
        Route::patch('/alerts/{alertRule}', [AlertRuleController::class, 'update'])->whereNumber('alertRule')->middleware('throttle:60,1')->name('alerts.update');
        Route::delete('/alerts/{alertRule}', [AlertRuleController::class, 'destroy'])->whereNumber('alertRule')->middleware('throttle:60,1')->name('alerts.destroy');
        Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->whereNumber('incident')->name('incidents.show');
        Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->whereNumber('incident')->middleware('throttle:60,1')->name('incidents.update');
        Route::get('/releases', [ReleaseController::class, 'index'])->name('releases.index');
        Route::get('/releases/{release}', [ReleaseController::class, 'show'])->whereNumber('release')->name('releases.show');
        Route::get('/issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
        Route::patch('/issues/{issue}', [IssueController::class, 'update'])->whereNumber('issue')->middleware('throttle:60,1')->name('issues.update');
        Route::get('/traces/{trace}', [TraceController::class, 'show'])->name('traces.show');
        Route::get('/traces/{trace}/events/{event}', [TraceEventController::class, 'show'])->whereNumber('event')->name('traces.events.show');
        Route::get('/settings/integrations', [IntegrationController::class, 'index'])->name('settings.integrations');
        Route::get('/settings/api', [ApiDocumentationController::class, 'show'])->name('settings.api');
        Route::get('/settings/data', [WorkspaceDataController::class, 'index'])->name('settings.data');
        Route::get('/settings/data/export', [WorkspaceDataController::class, 'export'])
            ->middleware(['verified', 'throttle:3,1'])
            ->name('settings.data.export');
        Route::get('/settings/notifications', [NotificationPreferenceController::class, 'index'])->name('settings.notifications');
        Route::patch('/settings/notifications', [NotificationPreferenceController::class, 'update'])->middleware('throttle:30,1')->name('settings.notifications.update');
        Route::get('/settings/audit-log', [AuditLogController::class, 'index'])->name('settings.audit-log');
        Route::get('/settings/billing', [BillingController::class, 'index'])->name('settings.billing');
        Route::post('/settings/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:20,1')->name('settings.billing.checkout');
        Route::post('/settings/billing/portal', [BillingController::class, 'portal'])->middleware('throttle:20,1')->name('settings.billing.portal');
        Route::get('/settings/billing/success', [BillingController::class, 'success'])->name('settings.billing.success');
        Route::get('/settings/billing/cancel', [BillingController::class, 'cancel'])->name('settings.billing.cancel');
        Route::get('/settings/team', [WorkspaceController::class, 'index'])->name('settings.team');
        Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
    });
});
