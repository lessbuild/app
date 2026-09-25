<?php

namespace App\Core\Http\Controllers;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Exceptions\Restoration\ResourceRestorationBlocked;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Models\ResourceRestorationRequest;
use App\Core\Models\Workspace;
use App\Core\Services\Restoration\ProductResourceRestorationRegistry;
use App\Core\Services\Restoration\RequestResourceRestoration;
use App\Core\Services\Restoration\ResourceRestorationAuthority;
use App\Core\Services\Restoration\RetryResourceRestoration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class ResourceRestorationsController
{
    public function show(Request $request, ResourceRestorationRequest $restoration, ResourceRestorationAuthority $authority, ProductResourceRestorationRegistry $providers): View
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor, $restoration, $authority);
        $canRetry = false;
        if (in_array($restoration->status, ['pending', 'blocked', 'failed', 'superseded'], true)) {
            try {
                $authority->authorize($actor, $restoration->target());
                $provider = $providers->get($restoration->product, $restoration->resource_type);
                $provider?->inspect($actor, $restoration->target());
                $canRetry = $provider !== null;
            } catch (Throwable) {
                // Progress remains readable while retries require current native management authority.
            }
        }
        $workspace = Workspace::query()->findOrFail($restoration->workspace_id);

        return view('core::restorations.show', [
            'user' => $actor, 'workspace' => $workspace, 'workspaces' => collect([$workspace]), 'contextProjects' => collect(),
            'restoration' => $restoration, 'canRetry' => $canRetry,
            'errorMessage' => match ($restoration->last_error_code) {
                'resource_mapping_changed', 'environment_mapping_changed', 'workspace_mapping_changed', 'product_mapping_changed' => __('The resource mapping changed. Reconcile its project mapping before starting a new restoration.'),
                'shared_environment_reconciliation_required' => __('This archived environment has multiple resource bindings. Reconcile its ownership before restoring it.'),
                'archive_provenance_required' => __('This imported archive needs its original parent relationship reconciled before restoration can continue.'),
                'product_reconciliation_required' => __('The Monitor project attachment needs reconciliation before restoration can continue.'),
                'source_revision_changed', 'source_receipt_changed' => __('The resource changed after this restoration was requested. Review its current archive state and start a new request.'),
                'restoration_lease_expired', 'restoration_temporarily_unavailable' => __('Restoration was interrupted. It will retry automatically when due.'),
                null => null,
                default => __('Restoration could not continue with the current access or resource state. Restore the shared project and check current project access before retrying.'),
            },
        ]);
    }

    public function retry(Request $request, ResourceRestorationRequest $restoration, ResourceRestorationAuthority $authority, RetryResourceRestoration $retry, RequestResourceRestoration $create): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeView($actor, $restoration, $authority);
        try {
            if ((string) $actor->getKey() !== $restoration->actor_id || $restoration->status === 'superseded') {
                abort_unless(in_array($restoration->status, ['pending', 'blocked', 'failed', 'superseded'], true), 409);
                // A new authorized manager gets a successor request with their own immutable
                // actor/revision. The abandoned actor's intent and receipt remain unchanged.
                $restoration = $create->request($actor, ProjectResource::query()->findOrFail($restoration->project_resource_id), (string) Str::uuid());
                $queued = true;
            } else {
                try {
                    $queued = $retry->retry($actor, $restoration);
                } catch (ResourceRestorationBlocked) {
                    // Reconciled mappings require a fresh immutable intent even when
                    // the same manager resumes. Full current authorization runs again.
                    $restoration = $create->request($actor, ProjectResource::query()->findOrFail($restoration->project_resource_id), (string) Str::uuid());
                    $queued = true;
                }
            }
        } catch (ResourceRestorationBlocked) {
            return redirect()->route('platform.resource-restorations.show', $restoration)
                ->withErrors(['restoration' => __('Current access or resource mappings prevent retry. Review the resource and its shared project before starting a new restoration.')]);
        }

        return redirect()->route('platform.resource-restorations.show', $restoration)
            ->with($queued ? 'success' : 'info', $queued ? __('Restoration queued for retry.') : __('This restoration is no longer retryable.'));
    }

    private function authorizeView(PlatformUser $actor, ResourceRestorationRequest $request, ResourceRestorationAuthority $authority): void
    {
        try {
            $authority->authorize($actor, $request->target(), purpose: ProjectResourceAccessPurpose::RetainedRead);
        } catch (ResourceRestorationBlocked) {
            abort(404);
        }
        // A historical request cannot reveal another tenant after a resource is remapped.
        abort_unless(ProjectResource::query()->whereKey($request->project_resource_id)->where('project_id', $request->project_id)
            ->where('product', $request->product)->where('resource_type', $request->resource_type)->where('resource_id', $request->resource_id)
            ->where('environment_id', $request->environment_id)->exists(), 404);
        $maps = LegacyIdentityMap::query()->where('source_product', $request->product)->where('source_entity', $request->resource_type)->where('source_id', $request->resource_id)->get();
        abort_unless($maps->count() <= 1, 404);
        if ($maps->isNotEmpty()) {
            $map = $maps->first();
            abort_unless($map->status === 'reconciled'
                && $map->canonical_entity === ($request->environment_id === null ? 'project' : 'project_environment')
                && (string) $map->canonical_id === (string) ($request->environment_id ?? $request->project_id)
                && (! isset($map->metadata['project_resource_id']) || (string) $map->metadata['project_resource_id'] === $request->project_resource_id), 404);
        }
    }

    private function actor(Request $request): PlatformUser
    {
        $actor = $request->user('platform');
        abort_unless($actor instanceof PlatformUser && $actor->status === 'active', 401);

        return $actor;
    }
}
