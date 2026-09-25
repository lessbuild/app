<?php

use App\Core\Http\Controllers\Auth\PlatformSessionController;
use App\Core\Http\Controllers\CoreCustomerStatusPageController;
use App\Core\Http\Controllers\CoreHelpController;
use App\Core\Http\Controllers\CoreHomeController;
use App\Core\Http\Controllers\CorePlatformStatusController;
use App\Core\Http\Controllers\MarketingController;
use App\Core\Http\Controllers\ProjectConnectionsController;
use App\Core\Http\Controllers\ProjectEnvironmentsController;
use App\Core\Http\Controllers\ProjectHandoverController;
use App\Core\Http\Controllers\WorkspaceAdministrationController;
use App\Core\Http\Controllers\WorkspaceCostBreakdownController;
use App\Core\Http\Controllers\WorkspaceCredentialInventoryController;
use App\Core\Http\Controllers\WorkspaceCustomerStatusPagesController;
use App\Core\Http\Controllers\WorkspaceDashboardController;
use App\Core\Http\Controllers\WorkspaceDashboardPreferencesController;
use App\Core\Http\Controllers\WorkspaceDirectoryController;
use App\Core\Http\Controllers\WorkspaceFeedbackController;
use App\Core\Http\Controllers\WorkspaceMonitorStatusPagesController;
use App\Core\Http\Controllers\WorkspaceNotificationInboxController;
use App\Core\Http\Controllers\WorkspaceProjectsController;
use App\Core\Http\Controllers\WorkspaceSearchController;
use App\Core\Http\Controllers\WorkspaceSubscriptionsController;
use App\Core\Http\Controllers\WorkspaceTeamController;
use App\Core\Http\Controllers\WorkspaceWebhookDeliveryHistoryController;
use App\Core\Http\Controllers\WorkspaceWorkflowActivityController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketingController::class, 'home'])->name('core.entry');
Route::get('/help', [CoreHelpController::class, 'index'])->name('core.help');
Route::get('/help/deployer', [CoreHelpController::class, 'deployerGuide'])->name('core.help.deployer');
Route::get('/help/deployer/api', [CoreHelpController::class, 'deployerApi'])->name('core.help.deployer.api');
Route::get('/help/monitor/api', [CoreHelpController::class, 'monitorApi'])->name('core.help.monitor.api');
Route::get('/help/analytics/api', [CoreHelpController::class, 'analyticsApi'])->name('core.help.analytics.api');
Route::get('/status', [CorePlatformStatusController::class, 'show'])->name('core.status');
Route::get('/status/report.json', [CorePlatformStatusController::class, 'report'])->name('core.status.report');
Route::get('/status/{product}/{slug}', CoreCustomerStatusPageController::class)
    ->whereIn('product', ['deployer', 'monitor'])
    ->name('core.status-pages.show');
Route::post('/status/{product}/{slug}/subscribe', [CoreCustomerStatusPageController::class, 'subscribe'])
    ->whereIn('product', ['deployer'])
    ->middleware('throttle:5,1')
    ->name('core.status-pages.subscribe');

// Keep local/test routes distinct when product hostnames are not configured.
$legalRoutePrefix = filled(config('platform.dashboard_host')) ? '' : 'core';
Route::prefix($legalRoutePrefix)->group(function (): void {
    Route::view('/privacy', 'core::marketing.privacy')->name('core.privacy');
    Route::view('/terms', 'core::marketing.terms')->name('core.terms');
});

Route::get('/{product}', [MarketingController::class, 'showProduct'])
    ->whereIn('product', ['deployer', 'monitor', 'analytics'])
    ->name('core.marketing.product');

Route::middleware('auth:platform')->group(function (): void {
    Route::get('/workspaces', CoreHomeController::class)->name('core.home');
    Route::get('/workspaces/manage', [WorkspaceDirectoryController::class, 'index'])->name('core.workspaces.index');
    Route::post('/workspaces', [WorkspaceDirectoryController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('core.workspaces.store');
    Route::post('/core/logout', [PlatformSessionController::class, 'destroy'])->name('core.logout');

    Route::post('/workspaces/{workspace}/select', [WorkspaceProjectsController::class, 'selectWorkspace'])
        ->name('core.workspaces.select');

    Route::get('/workspaces/{workspace}/overview', WorkspaceDashboardController::class)
        ->name('core.workspace.dashboard');

    Route::get('/workspaces/{workspace}/subscriptions', WorkspaceSubscriptionsController::class)
        ->name('core.workspace.subscriptions');

    Route::get('/workspaces/{workspace}/manage', WorkspaceAdministrationController::class)
        ->name('core.workspace.admin');

    Route::get('/workspaces/{workspace}/costs', WorkspaceCostBreakdownController::class)
        ->name('core.workspace.costs');
    Route::patch('/workspaces/{workspace}/costs/budget', [WorkspaceCostBreakdownController::class, 'updateBudget'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.costs.budget.update');

    Route::get('/workspaces/{workspace}/status-pages', [WorkspaceCustomerStatusPagesController::class, 'index'])
        ->name('core.workspace.status-pages.index');
    Route::post('/workspaces/{workspace}/status-pages', [WorkspaceCustomerStatusPagesController::class, 'storePage'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.status-pages.store');
    Route::patch('/workspaces/{workspace}/status-pages/{page}', [WorkspaceCustomerStatusPagesController::class, 'updatePage'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.status-pages.update');
    Route::delete('/workspaces/{workspace}/status-pages/{page}', [WorkspaceCustomerStatusPagesController::class, 'destroyPage'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.status-pages.destroy');
    Route::post('/workspaces/{workspace}/status-pages/incidents', [WorkspaceCustomerStatusPagesController::class, 'storeIncident'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.status-pages.incidents.store');
    Route::patch('/workspaces/{workspace}/status-pages/incidents/{incident}', [WorkspaceCustomerStatusPagesController::class, 'updateIncident'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.status-pages.incidents.update');

    Route::get('/workspaces/{workspace}/monitor-status-pages', [WorkspaceMonitorStatusPagesController::class, 'index'])
        ->name('core.workspace.monitor-status-pages.index');
    Route::post('/workspaces/{workspace}/monitor-status-pages', [WorkspaceMonitorStatusPagesController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.monitor-status-pages.store');
    Route::patch('/workspaces/{workspace}/monitor-status-pages/{page}', [WorkspaceMonitorStatusPagesController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.monitor-status-pages.update');
    Route::delete('/workspaces/{workspace}/monitor-status-pages/{page}', [WorkspaceMonitorStatusPagesController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.monitor-status-pages.destroy');

    Route::get('/workspaces/{workspace}/feedback', [WorkspaceFeedbackController::class, 'index'])
        ->name('core.workspace.feedback.index');
    Route::post('/workspaces/{workspace}/feedback', [WorkspaceFeedbackController::class, 'store'])
        ->middleware('throttle:10,60')
        ->name('core.workspace.feedback.store');
    Route::patch('/workspaces/{workspace}/feedback/{feedback}', [WorkspaceFeedbackController::class, 'update'])
        ->scopeBindings()
        ->middleware('throttle:30,1')
        ->name('core.workspace.feedback.update');
    Route::delete('/workspaces/{workspace}/feedback/{feedback}', [WorkspaceFeedbackController::class, 'destroy'])
        ->scopeBindings()
        ->middleware('throttle:30,1')
        ->name('core.workspace.feedback.destroy');

    Route::get('/workspaces/{workspace}/workflows', WorkspaceWorkflowActivityController::class)
        ->name('core.workspace.workflows');

    Route::get('/workspaces/{workspace}/deliveries', WorkspaceWebhookDeliveryHistoryController::class)
        ->name('core.workspace.deliveries');

    Route::get('/workspaces/{workspace}/credentials', WorkspaceCredentialInventoryController::class)
        ->name('core.workspace.credentials');

    Route::get('/workspaces/{workspace}/notifications', WorkspaceNotificationInboxController::class)
        ->name('core.workspace.notifications');
    Route::post('/workspaces/{workspace}/notifications/read-all', [WorkspaceNotificationInboxController::class, 'markAllRead'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.notifications.read-all');
    Route::post('/workspaces/{workspace}/notifications/{notificationKey}/read', [WorkspaceNotificationInboxController::class, 'markRead'])
        ->where('notificationKey', '[a-f0-9]{64}')
        ->middleware('throttle:60,1')
        ->name('core.workspace.notifications.read');
    Route::delete('/workspaces/{workspace}/notifications/{notificationKey}/read', [WorkspaceNotificationInboxController::class, 'markUnread'])
        ->where('notificationKey', '[a-f0-9]{64}')
        ->middleware('throttle:60,1')
        ->name('core.workspace.notifications.unread');
    Route::put('/workspaces/{workspace}/notifications/preferences', [WorkspaceNotificationInboxController::class, 'updatePreference'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.notifications.preferences.update');
    Route::delete('/workspaces/{workspace}/notifications/preferences/{preference}', [WorkspaceNotificationInboxController::class, 'destroyPreference'])
        ->middleware('throttle:30,1')
        ->name('core.workspace.notifications.preferences.destroy');

    Route::post('/workspaces/{workspace}/dashboard/views', [WorkspaceDashboardPreferencesController::class, 'storeView'])
        ->name('core.workspace.views.store');
    Route::put('/workspaces/{workspace}/dashboard/views/{view}', [WorkspaceDashboardPreferencesController::class, 'updateView'])
        ->scopeBindings()
        ->name('core.workspace.views.update');
    Route::delete('/workspaces/{workspace}/dashboard/views/{view}', [WorkspaceDashboardPreferencesController::class, 'destroyView'])
        ->scopeBindings()
        ->name('core.workspace.views.destroy');
    Route::put('/workspaces/{workspace}/projects/{project}/pins/{visibility}', [WorkspaceDashboardPreferencesController::class, 'pin'])
        ->scopeBindings()
        ->whereIn('visibility', ['personal', 'workspace'])
        ->name('core.workspace.project-pins.update');
    Route::delete('/workspaces/{workspace}/projects/{project}/pins/{visibility}', [WorkspaceDashboardPreferencesController::class, 'unpin'])
        ->scopeBindings()
        ->whereIn('visibility', ['personal', 'workspace'])
        ->name('core.workspace.project-pins.destroy');

    Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class)
        ->middleware('throttle:60,1')
        ->name('core.workspace.search');

    Route::prefix('workspaces/{workspace}/team')
        ->scopeBindings()
        ->name('core.workspace.team.')
        ->controller(WorkspaceTeamController::class)
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::post('/invitations', 'storeInvitation')->middleware('throttle:10,1')->name('invitations.store');
            Route::delete('/invitations/{invitation}', 'revokeInvitation')->name('invitations.destroy');
            Route::put('/memberships/{membership}/role', 'updateRole')->name('memberships.role.update');
            Route::put('/memberships/{membership}/products/{product}', 'updateProductAccess')
                ->whereIn('product', ['deployer', 'monitor', 'analytics'])
                ->name('memberships.products.update');
            Route::delete('/memberships/{membership}', 'revokeMembership')->name('memberships.destroy');
        });

    Route::prefix('workspaces/{workspace}')
        ->scopeBindings()
        ->name('core.projects.')
        ->controller(WorkspaceProjectsController::class)
        ->group(function (): void {
            Route::get('/projects', 'index')->name('index');
            Route::get('/projects/handover', [ProjectHandoverController::class, 'form'])->name('handover.form');
            Route::post('/projects/handover/validate', [ProjectHandoverController::class, 'validateManifest'])
                ->middleware('throttle:10,1')
                ->name('handover.validate');
            Route::get('/projects/create', 'create')->name('create');
            Route::post('/projects', 'store')->name('store');
            Route::get('/projects/{project}/handover', [ProjectHandoverController::class, 'export'])->name('handover.export');
            Route::get('/projects/{project}/edit', 'edit')->name('edit');
            Route::put('/projects/{project}', 'update')->name('update');
            Route::get('/projects/{project}', 'show')->name('show');
            Route::post('/projects/{project}/archive', 'archive')->name('archive');
            Route::post('/projects/{project}/restore', 'restore')->name('restore');
            Route::post('/projects/{project}/resources', 'storeResource')->name('resources.store');
            Route::post('/projects/{project}/environments', [ProjectEnvironmentsController::class, 'store'])
                ->name('environments.store');
            Route::post('/projects/{project}/connections', [ProjectConnectionsController::class, 'store'])
                ->name('connections.store');
            Route::delete('/projects/{project}/connections/{connection}', [ProjectConnectionsController::class, 'destroy'])
                ->name('connections.destroy');
            Route::post('/projects/{project}/connections/{connection}/deliveries/{delivery}/retry', [ProjectConnectionsController::class, 'retry'])
                ->name('connections.deliveries.retry');
            Route::post('/projects/{project}/connections/{connection}/automation', [ProjectConnectionsController::class, 'automation'])
                ->name('connections.automation');
        });
});
