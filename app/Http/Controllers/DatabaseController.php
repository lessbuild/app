<?php

namespace App\Http\Controllers;

use App\Jobs\Database\CloneDatabaseJob;
use App\Jobs\Database\CollectDatabaseSnapshotJob;
use App\Jobs\Database\ManageDatabaseUserJob;
use App\Models\DatabaseClone;
use App\Models\DatabaseUser;
use App\Models\EnvironmentResource;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DatabaseController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

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

    public function inspect(EnvironmentResource $resource): RedirectResponse
    {
        $this->ensureResourceAbility($resource, 'view');
        $this->entitlements->enforce($resource->environment->project->organization, 'resources');
        abort_unless(in_array($resource->type, ['mysql', 'postgresql'], true), 422);
        CollectDatabaseSnapshotJob::dispatch($resource->id);

        return back()->with('success', __('Database inspection queued.'));
    }

    public function storeUser(Request $request, EnvironmentResource $resource): RedirectResponse
    {
        $this->ensureResourceAbility($resource, 'manage');
        $this->entitlements->enforce($resource->environment->project->organization, 'resources');
        abort_unless(in_array($resource->type, ['mysql', 'postgresql'], true), 422);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:40', 'regex:/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', Rule::unique('database_users')->where('environment_resource_id', $resource->id)],
            'privilege' => ['required', Rule::in(['read', 'write', 'admin'])],
            'expires_in_days' => ['nullable', 'integer', Rule::in([1, 7, 30, 90])],
        ]);
        $password = Str::password(32);
        $user = $resource->databaseUsers()->create([
            'created_by' => $request->user()->id,
            'username' => $data['username'],
            'password' => $password,
            'privilege' => $data['privilege'],
            'expires_at' => filled($data['expires_in_days'] ?? null) ? now()->addDays((int) $data['expires_in_days']) : null,
        ]);
        ManageDatabaseUserJob::dispatch($user->id, 'apply');

        return back()->with('success', __('Database user queued. Copy the password now; it will not be shown again.'))->with('databasePassword', $password);
    }

    public function destroyUser(DatabaseUser $databaseUser): RedirectResponse
    {
        $this->ensureResourceAbility($databaseUser->resource, 'manage');
        $this->entitlements->enforce($databaseUser->resource->environment->project->organization, 'resources');
        ManageDatabaseUserJob::dispatch($databaseUser->id, 'remove');

        return back()->with('success', __('Database user removal queued.'));
    }

    public function clone(Request $request, EnvironmentResource $resource): RedirectResponse
    {
        $this->ensureResourceAbility($resource, 'manage');
        $this->entitlements->enforce($resource->environment->project->organization, 'resources');
        $data = $request->validate([
            'target_resource_id' => ['required', 'integer', 'different:source_resource_id'],
            'confirmation' => ['required', 'string', 'max:50'],
        ]);
        $target = EnvironmentResource::query()->findOrFail($data['target_resource_id']);
        $this->ensureResourceAbility($target, 'manage');
        abort_unless($target->environment->project->organization_id === $resource->environment->project->organization_id, 403);
        abort_unless($target->type === $resource->type && $target->id !== $resource->id, 422, 'Choose another database of the same type.');
        abort_if($target->environment->type === 'production', 422, 'Production databases cannot be clone targets.');
        if (! hash_equals($target->name, $data['confirmation'])) {
            return back()->withErrors(['confirmation' => __('Type the target database resource name exactly to confirm replacement.')])->withInput();
        }
        $clone = DatabaseClone::query()->create([
            'source_resource_id' => $resource->id,
            'target_resource_id' => $target->id,
            'requested_by' => $request->user()->id,
            'status' => 'queued',
        ]);
        CloneDatabaseJob::dispatch($clone->id);

        return back()->with('success', __('Database clone queued. The target will be replaced.'));
    }

    private function ensureResourceAbility(EnvironmentResource $resource, string $ability): void
    {
        $environment = $resource->environment()->with('project.organization')->firstOrFail();
        abort_unless($environment->project->organization_id === request()->user()->current_organization_id
            && $environment->project->organization->permits(request()->user(), $ability), 403);
    }
}
