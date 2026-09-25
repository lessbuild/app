<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Database\CreateDatabaseUserAction;
use App\Modules\Deployer\Actions\Database\QueueDatabaseCloneAction;
use App\Modules\Deployer\Actions\Database\QueueDatabaseInspectionAction;
use App\Modules\Deployer\Actions\Database\QueueDatabaseUserApplyAction;
use App\Modules\Deployer\Actions\Database\QueueDatabaseUserRemovalAction;
use App\Modules\Deployer\Data\DatabaseCloneResult;
use App\Modules\Deployer\Exceptions\DatabaseCloneException;
use App\Modules\Deployer\Http\Requests\CloneDatabaseRequest;
use App\Modules\Deployer\Http\Requests\StoreDatabaseUserRequest;
use App\Modules\Deployer\Models\DatabaseClone;
use App\Modules\Deployer\Models\DatabaseUser;
use App\Modules\Deployer\Models\EnvironmentResource;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DatabaseController extends Controller
{
    /**
     * Use workspace resource entitlements to gate database inspection and management.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Render current-workspace database resources, bounded snapshot and clone history, and management availability.
     */
    public function index(Request $request): View
    {
        $resources = EnvironmentResource::query()
            ->whereIn('type', ['mysql', 'postgresql'])
            ->whereHas('environment.project', fn ($query) => $query->where('organization_id', $request->user()->current_organization_id))
            ->with(['environment.project', 'snapshots' => fn ($query) => $query->latest('collected_at')->limit(20), 'databaseUsers'])
            ->orderBy('name')->get();

        return view('databases.index', [
            'resources' => $resources,
            'clones' => DatabaseClone::query()->whereHas('source.environment.project', fn ($query) => $query->where('organization_id', $request->user()->current_organization_id))->with(['source', 'target'])->latest()->limit(30)->get(),
            'canManage' => $request->user()->currentOrganization->permits($request->user(), 'manage')
                && $this->entitlements->allows($request->user()->currentOrganization, 'resources'),
            'featureAvailable' => $this->entitlements->allows($request->user()->currentOrganization, 'resources'),
        ]);
    }

    /**
     * Require visibility and resource entitlement for a supported database, then queue a fresh inspection snapshot.
     */
    public function inspect(EnvironmentResource $resource, QueueDatabaseInspectionAction $queueInspection): RedirectResponse
    {
        $this->loadResource($resource);
        $this->authorize('view', $resource);
        $this->entitlements->enforce($resource->environment->project->organization, 'resources');
        abort_unless(in_array($resource->type, ['mysql', 'postgresql'], true), 422);
        $queueInspection->handle($resource);

        return back()->with('success', __('Database inspection queued.'));
    }

    /**
     * Validate a unique database username, privilege, and optional expiry for an authorized resource, then queue creation.
     *
     * @return RedirectResponse The generated password flashed for one-time display.
     */
    public function storeUser(StoreDatabaseUserRequest $request, EnvironmentResource $resource, CreateDatabaseUserAction $createUser): RedirectResponse
    {
        $this->loadResource($resource);
        $this->authorize('manage', $resource);
        $result = $createUser->handle($resource, $request->user(), $request->validated());

        return back()->with('success', __('Database user queued. Copy the password now; it will not be shown again.'))->with('databasePassword', $result->password);
    }

    /**
     * Retry a failed or still-unapplied credential setup using its encrypted stored password.
     */
    public function retryUser(DatabaseUser $databaseUser, QueueDatabaseUserApplyAction $queueApply): RedirectResponse
    {
        $resource = $databaseUser->resource;
        $this->loadResource($resource);
        $this->authorize('manage', $resource);
        $this->entitlements->enforce($resource->environment->project->organization, 'resources');
        $queued = $queueApply->handle($databaseUser);

        return back()->with('success', $queued
            ? __('Database user setup queued.')
            : __('Database user setup is already underway.'));
    }

    /**
     * Require resource management permission and entitlement, then queue removal of the bound database user.
     */
    public function destroyUser(DatabaseUser $databaseUser, QueueDatabaseUserRemovalAction $queueRemoval): RedirectResponse
    {
        $resource = $databaseUser->resource;
        $this->loadResource($resource);
        $this->authorize('manage', $resource);
        $this->entitlements->enforce($resource->environment->project->organization, 'resources');
        $queueRemoval->handle($databaseUser);

        return back()->with('success', __('Database user removal queued.'));
    }

    /**
     * Validate a distinct same-type non-production target in the source workspace and require its exact name as confirmation.
     *
     * @return RedirectResponse A queued replacement result or a confirmation validation error.
     */
    public function clone(CloneDatabaseRequest $request, EnvironmentResource $resource, QueueDatabaseCloneAction $queueClone): RedirectResponse
    {
        $this->loadResource($resource);
        $this->authorize('manage', $resource);
        $data = $request->validated();
        $target = EnvironmentResource::query()->findOrFail($data['target_resource_id']);
        $this->loadResource($target);
        $this->authorize('manage', $target);
        try {
            $result = $queueClone->handle($resource, $target, $request->user(), $data['confirmation']);
        } catch (DatabaseCloneException $exception) {
            abort(422, $exception->getMessage());
        }
        if ($result->status === DatabaseCloneResult::CONFIRMATION_MISMATCH) {
            return back()->withErrors(['confirmation' => __('Type the target database resource name exactly to confirm replacement.')])->withInput();
        }

        return back()->with('success', __('Database clone queued. The target will be replaced.'));
    }

    /**
     * Require the resource's environment to belong to the current workspace and the user to hold the requested ability.
     */
    private function loadResource(EnvironmentResource $resource): void
    {
        $resource->loadMissing('environment.project.organization');
        abort_unless($resource->environment !== null, 404);
    }
}
