<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Exceptions\Restoration\ResourceRestorationBlocked;
use App\Core\Exceptions\Restoration\ResourceRestorationSuperseded;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProjectResource;
use App\Core\Models\ResourceRestorationRequest;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\Restoration\RequestResourceRestoration;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Bridges mapped resources to durable Core requests and preserves native legacy actions. */
final class RestoreMonitorResource
{
    public function __construct(private readonly ProductAuthentication $authentication, private readonly ResolvePlatformUser $users) {}

    public function restore(User $actor, Application|Environment $resource, string $idempotencyKey): ?ResourceRestorationRequest
    {
        Gate::forUser($actor)->authorize('restore', $resource);
        $mapped = $this->mapping($resource);
        if ($mapped !== null) {
            $user = $this->users->resolve($actor, 'monitor');
            abort_if($user === null, 404);

            try {
                return app(RequestResourceRestoration::class)->request($user, $mapped, $idempotencyKey);
            } catch (ResourceRestorationBlocked|ResourceRestorationSuperseded $exception) {
                throw ValidationException::withMessages(['restoration' => 'Restoration could not start because access, lifecycle state, or the Core mapping changed. Reload the page and reconcile the linked project before trying again.']);
            }
        }

        DB::connection('monitor')->transaction(function () use ($actor, $resource): void {
            $application = Application::withTrashed()->lockForUpdate()->findOrFail($resource instanceof Application ? $resource->getKey() : $resource->application_id);
            $application->setRelation('workspace', Workspace::query()->lockForUpdate()->findOrFail($application->workspace_id));
            $locked = $resource instanceof Application ? $application
                : $application->environments()->withTrashed()->lockForUpdate()->findOrFail($resource->getKey());
            if ($locked instanceof Environment) {
                abort_if($application->trashed(), 404);
                $locked->setRelation('application', $application);
            }
            Gate::forUser($actor)->authorize('restore', $locked);
            // Mapping can be added while waiting for the native lock. Never bypass it.
            if ($this->mapping($locked) !== null) {
                throw ValidationException::withMessages(['restoration' => 'This resource was linked to Core. Reload this page and restore it again.']);
            }
            abort_unless($locked->trashed(), 404);
            $locked->restore();
            $application->increment('lifecycle_revision');
        }, attempts: 3);

        return null;
    }

    /** View callers must first authorize retained native access. */
    public function viewData(User $actor, Application|Environment $resource): array
    {
        $data = ['coreProjectRestoreUrl' => null, 'restorationProgressUrl' => null, 'needsRestoration' => $resource->trashed()];
        if (! $this->authentication->usesCoreAuthority('monitor')) {
            return $data;
        }
        $type = $resource instanceof Application ? 'application' : 'environment';
        $mapped = ProjectResource::query()->where('product', 'monitor')->where('resource_type', $type)->where('resource_id', (string) $resource->getKey())->with('project')->first();
        if ($mapped === null) {
            return $data;
        }
        $data['needsRestoration'] = $data['needsRestoration'] || $mapped->status === 'archived';
        if ($mapped->project !== null && ($mapped->project->status === 'archived' || $mapped->project->archived_at !== null)) {
            $data['coreProjectRestoreUrl'] = route('core.projects.index', ['workspace' => $mapped->project->workspace_id, 'status' => 'archived']);
        }
        $actorId = $this->users->resolve($actor, 'monitor')?->getKey();
        if ($actorId !== null && Gate::forUser($actor)->allows('restore', $resource)) {
            $application = $resource instanceof Application ? $resource : $resource->application()->withTrashed()->first();
            $pending = ResourceRestorationRequest::query()->where('actor_id', $actorId)->where('project_resource_id', $mapped->getKey())
                ->where('product', 'monitor')->where('resource_type', $type)->where('resource_id', (string) $resource->getKey())
                ->where('project_id', $mapped->project_id)->where('workspace_id', $mapped->project?->workspace_id)
                ->where('environment_id', $mapped->environment_id)->where('source_workspace_entity', 'workspace')
                ->where('source_workspace_id', (string) $application?->workspace_id)
                ->where('source_parent_id', $resource instanceof Environment ? (string) $resource->application_id : null)
                ->whereNotIn('status', ['completed', 'superseded'])->latest('created_at')->first();
            if ($pending !== null) {
                $data['restorationProgressUrl'] = route('platform.resource-restorations.show', $pending);
            }
        }

        return $data;
    }

    /** @return list<string> */
    public function retainedApplicationIds(): array
    {
        if (! $this->authentication->usesCoreAuthority('monitor')) {
            return [];
        }

        return ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'application')
            ->where('status', 'archived')->pluck('resource_id')->all();
    }

    private function mapping(Application|Environment $resource): ?ProjectResource
    {
        if (! $this->authentication->usesCoreAuthority('monitor')) {
            return null;
        }
        $type = $resource instanceof Application ? 'application' : 'environment';
        $maps = ProjectResource::query()->where('product', 'monitor')->where('resource_type', $type)->where('resource_id', (string) $resource->getKey())->get();
        if ($maps->count() > 1 || ($maps->isEmpty() && LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', $type)->where('source_id', (string) $resource->getKey())->exists())) {
            throw ValidationException::withMessages(['restoration' => 'The Core resource mapping needs reconciliation before restoration.']);
        }

        if ($maps->isEmpty() && $resource instanceof Application) {
            $children = $resource->environments()->withTrashed()->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $mappedChildren = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')->whereIn('resource_id', $children)
                ->where('metadata->source_application_id', (string) $resource->getKey())->exists()
                || LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'environment')->whereIn('source_id', $children)
                    ->where('metadata->source_application_id', (string) $resource->getKey())->exists();
            if ($mappedChildren) {
                throw ValidationException::withMessages(['restoration' => 'This application has linked environments. Reconcile its Core application mapping before restoration.']);
            }
        }

        return $maps->first();
    }
}
