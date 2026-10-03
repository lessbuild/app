<?php

declare(strict_types=1);

// The browser API behind the Next.js frontend, under /api/app. These routes use the `web` middleware group: the
// session cookie signs people in, and writes need the X-XSRF-TOKEN header from the XSRF-TOKEN cookie.

use App\Http\Controllers\Api\App\Shell\ShowShellController;
use App\Http\Controllers\Api\App\SiteAudits\DeleteSiteAuditController;
use App\Http\Controllers\Api\App\SiteAudits\ListSiteAuditsController;
use App\Http\Controllers\Api\App\SiteAudits\ShowSiteAuditController;
use App\Http\Controllers\Api\App\SiteAudits\ShowSiteAuditFileController;
use App\Http\Controllers\Api\App\SiteAudits\ShowSiteAuditReportController;
use App\Http\Controllers\Api\App\SiteAudits\StartSiteAuditRunController;
use App\Http\Controllers\Api\App\SiteAudits\StoreSiteAuditController;
use App\Http\Controllers\Api\App\SiteAudits\SuggestCompetitorsController;
use App\Http\Controllers\Api\App\SiteAudits\UpdateSiteAuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'account.security'])->group(function (): void {
    // The frame around pages; {service} names the service the page belongs to, for its section navigation.
    Route::get('/shell/{service?}', ShowShellController::class)->where('service', '[a-z]+')->name('shell');

    Route::prefix('/projects/{project}')->middleware('project.context')->scopeBindings()->group(function (): void {
        Route::get('/shell/{service?}', ShowShellController::class)->where('service', '[a-z]+')->name('projects.shell');
        Route::prefix('/audit')->middleware('service:audit')->name('audit.')->group(function (): void {
            Route::get('/', ListSiteAuditsController::class)->name('index');
            Route::post('/', StoreSiteAuditController::class)->middleware('throttle:20,1')->name('store');
            Route::post('/competitor-suggestions', SuggestCompetitorsController::class)->middleware('throttle:10,1')->name('suggestions');
            Route::get('/runs/{siteAuditRun}', ShowSiteAuditReportController::class)->whereNumber('siteAuditRun')->name('runs.show');
            Route::get('/runs/{siteAuditRun}/files/{file}', ShowSiteAuditFileController::class)->whereNumber('siteAuditRun')
                ->where('file', '[A-Za-z0-9-]{1,60}\.(jpg|png)')->name('files');
            Route::get('/{siteAudit}', ShowSiteAuditController::class)->whereNumber('siteAudit')->name('show');
            Route::put('/{siteAudit}', UpdateSiteAuditController::class)->whereNumber('siteAudit')->middleware('throttle:30,1')->name('update');
            Route::delete('/{siteAudit}', DeleteSiteAuditController::class)->whereNumber('siteAudit')->middleware('throttle:30,1')->name('destroy');
            Route::post('/{siteAudit}/runs', StartSiteAuditRunController::class)->whereNumber('siteAudit')->middleware('throttle:10,1')->name('runs.store');
        });
    });
});
