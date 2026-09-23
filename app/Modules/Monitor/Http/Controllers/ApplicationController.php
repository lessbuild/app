<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Data\Telemetry\IssuedIngestToken;
use App\Modules\Monitor\Http\Requests\StoreApplicationRequest;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ArchiveApplication;
use App\Modules\Monitor\Services\CreateIngestToken;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function index(Request $request, CurrentWorkspace $currentWorkspace, WorkspacePlanLimits $limits): View
    {
        $workspace = $currentWorkspace->get();
        $archived = $request->query('status') === 'archived';
        $applicationCapacity = $limits->applicationCapacity($workspace);

        return view('monitor::applications.index', [
            'applications' => $workspace->applications()->when($archived, fn ($query) => $query->onlyTrashed())
                ->withCount('environments')->withSum('environments', 'event_count')
                ->orderBy('name')->orderBy('id')->paginate(12)->withQueryString(),
            'archived' => $archived,
            'canManage' => Gate::allows('update', $workspace),
            'canCreate' => Gate::allows('update', $workspace) && ! $applicationCapacity['at_limit'],
            'applicationCapacity' => $applicationCapacity,
        ]);
    }

    public function create(CurrentWorkspace $currentWorkspace): View
    {
        Gate::authorize('update', $currentWorkspace->get());

        return view('monitor::applications.form', ['application' => new Application(['accent' => 'violet', 'framework' => 'Laravel'])]);
    }

    public function store(StoreApplicationRequest $request, CurrentWorkspace $currentWorkspace, CreateIngestToken $createToken, WorkspacePlanLimits $limits): RedirectResponse
    {
        $workspace = $currentWorkspace->get();
        $issued = DB::connection('monitor')->transaction(function () use ($request, $workspace, $createToken, $limits): IssuedIngestToken {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $limits->assertApplicationCapacity($workspace);
            $application = $workspace->applications()->create(
                $request->validated() + ['slug' => Str::slug($request->validated('name')).'-'.Str::uuid(), 'accent' => 'violet'],
            );
            $environment = $application->environments()->create(['name' => 'Production', 'slug' => 'production', 'status' => 'active']);

            return $createToken->create($environment, $request->user(), 'Initial collector');
        });

        return to_route('monitor.environments.show', [$issued->token->environment->application_id, $issued->token->environment_id])
            ->with('status', 'Application created. Save your token and send your first event.')
            ->with('issued_ingest_token', ['environment_id' => $issued->token->environment_id, 'encrypted_secret' => Crypt::encryptString($issued->secret)]);
    }

    public function show(Request $request, Application $application): View
    {
        Gate::authorize('view', $application);

        return view('monitor::applications.show', [
            'application' => $application,
            'environments' => $application->environments()->withTrashed()
                ->withCount(['ingestTokens as active_token_count' => fn ($query) => $query->active()])
                ->orderBy('name')->orderBy('id')->get(),
            'canManage' => Gate::allows('update', $application->workspace),
        ]);
    }

    public function edit(Application $application): View
    {
        Gate::authorize('update', $application);

        return view('monitor::applications.form', ['application' => $application]);
    }

    public function update(StoreApplicationRequest $request, Application $application): RedirectResponse
    {
        $application->update($request->validated());

        return to_route('monitor.applications.show', $application)->with('status', 'Application updated.');
    }

    public function destroy(Request $request, Application $application, ArchiveApplication $archive): RedirectResponse
    {
        Gate::authorize('delete', $application);
        $request->validate(['confirmation' => ['required', Rule::in([$application->name])]]);
        $archive->archive($application);

        return to_route('monitor.applications.index')->with('status', 'Application archived and its ingestion tokens revoked. Telemetry has been preserved.');
    }

    public function restore(Application $application): RedirectResponse
    {
        Gate::authorize('restore', $application);
        abort_unless($application->trashed(), 404);
        $application->restore();

        return to_route('monitor.applications.show', $application)->with('status', 'Application restored. Issue new tokens before reconnecting your collectors.');
    }
}
