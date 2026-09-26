<?php

namespace App\Core\Services;

use App\Core\Enums\WorkspaceRolloutOutcome;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceFeatureRollout;
use App\Core\Models\WorkspaceFeatureRolloutChange;
use App\Core\Models\WorkspaceFeatureRolloutMetric;
use App\Core\Models\WorkspaceMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** UI availability only. Callers must still enforce every product policy and entitlement. */
final class WorkspaceFeatureRollouts
{
    public const FEATURES = ['credential_inventory', 'delivery_history'];

    public function authorize(PlatformUser $user, Workspace $workspace, bool $manage = false): WorkspaceMembership
    {
        abort_unless(PlatformUser::query()->whereKey($user->getKey())->where('status', 'active')->exists(), 403);
        abort_unless(Workspace::query()->whereKey($workspace->getKey())
            ->where('status', 'active')->whereNull('archived_at')->exists(), 404);

        $query = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->currentlyActive();
        $membership = ($manage && DB::connection('core')->transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first();
        abort_if($membership === null, 404);
        abort_if($manage && ! in_array($membership->role, ['owner', 'admin'], true), 403);

        return $membership;
    }

    /** @return array{label: string, description: string, available: bool, managed: bool, default: bool, override: ?bool, enabled: bool} */
    public function state(Workspace $workspace, string $feature): array
    {
        abort_unless(in_array($feature, self::FEATURES, true), 404);
        $definition = config('workspace-rollouts.features.'.$feature);
        abort_unless(is_array($definition), 404);
        $workspaceIds = $definition['workspace_ids'] ?? null;
        $available = ($definition['available'] ?? false) === true
            && ($workspaceIds === null || (is_array($workspaceIds) && in_array((string) $workspace->getKey(), $workspaceIds, true)));
        $managed = ($definition['workspace_managed'] ?? false) === true;
        $default = ($definition['default_enabled'] ?? false) === true;
        $override = WorkspaceFeatureRollout::query()->where('workspace_id', $workspace->getKey())
            ->where('feature', $feature)->first()?->enabled;

        return [
            'label' => (string) ($definition['label'] ?? $feature),
            'description' => (string) ($definition['description'] ?? ''),
            'available' => $available,
            'managed' => $managed,
            'default' => $default,
            'override' => $override,
            'enabled' => $available && ($managed ? ($override ?? $default) : $default),
        ];
    }

    public function change(PlatformUser $user, Workspace $workspace, string $feature, ?bool $enabled): void
    {
        DB::connection('core')->transaction(function () use ($user, $workspace, $feature, $enabled): void {
            $currentWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $this->authorize($user, $currentWorkspace, manage: true);
            $state = $this->state($currentWorkspace, $feature);

            if (! $state['managed'] || ($enabled === true && ! $state['available'])) {
                throw ValidationException::withMessages(['state' => __('This feature is restricted by the server rollout policy.')]);
            }

            if ($state['override'] === $enabled) {
                return;
            }

            $identity = ['workspace_id' => $currentWorkspace->getKey(), 'feature' => $feature];
            if ($enabled === null) {
                WorkspaceFeatureRollout::query()->where($identity)->delete();
            } else {
                WorkspaceFeatureRollout::query()->updateOrCreate($identity, ['enabled' => $enabled]);
            }

            WorkspaceFeatureRolloutChange::query()->create([
                ...$identity,
                'actor_user_id' => $user->getKey(),
                'previous_enabled' => $state['override'],
                'enabled' => $enabled,
            ]);
        });
    }

    /** One aggregate row per workspace/feature/UTC day; never record request data or exceptions. */
    public function record(Workspace $workspace, string $feature, WorkspaceRolloutOutcome $outcome): void
    {
        if (! in_array($feature, self::FEATURES, true)) {
            return;
        }

        $now = now()->utc();
        $identity = ['workspace_id' => $workspace->getKey(), 'feature' => $feature, 'day' => $now->toDateString()];
        $timestamps = ['updated_at' => $now];
        if ($outcome === WorkspaceRolloutOutcome::Exposed) {
            $timestamps['last_exposed_at'] = $now;
        }
        if (in_array($outcome, [WorkspaceRolloutOutcome::Degraded, WorkspaceRolloutOutcome::Failed], true)) {
            $timestamps['last_failure_at'] = $now;
        }

        try {
            WorkspaceFeatureRolloutMetric::query()->insertOrIgnore([
                ...$identity, 'id' => (string) Str::ulid(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            WorkspaceFeatureRolloutMetric::query()->where($identity)->increment($outcome->value.'_count', 1, $timestamps);
        } catch (QueryException) {
            // Telemetry must neither hide the original failure nor fail an otherwise usable page.
            Log::warning('Workspace rollout metrics could not be recorded.', ['feature' => $feature]);
        }
    }
}
