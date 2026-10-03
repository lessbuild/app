<?php

declare(strict_types=1);

// The API behind the Next.js app, under /api/app. These routes use the `web` middleware group: the session cookie
// signs people in, and writes need the X-XSRF-TOKEN header from the XSRF-TOKEN cookie. Sign-in, sign-up, two-factor
// and password reset are Fortify's routes under /api/app/auth (config/fortify.php).

use App\Http\Controllers\AccessRequests\ShowAccessRequestFormController;
use App\Http\Controllers\AccessRequests\StoreAccessRequestController;
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
Route::get('/invitations/{token}', ShowInvitationController::class)->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:30,1')->name('invitations.show');

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
});
