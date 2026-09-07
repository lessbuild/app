<?php

namespace App\Actions\Repository;

use App\Data\BuildPromotionResult;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Notifications\PromotionApprovalRequestedNotification;
use App\Services\DeploymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromoteBuildAction
{
    /**
     * Use the deployment request service to persist and dispatch approved promotion requests.
     *
     * @param  DeploymentRequest  $deployments  Service that persists deployment requests and dispatches eligible builds.
     */
    public function __construct(private readonly DeploymentRequest $deployments) {}

    /**
     * Validate workspace access, source identity, and forward environment order before creating a promotion under locks; notify required approvers and dispatch eligible work.
     *
     * @param  Build  $source  Successful build whose exact revision will be promoted.
     * @param  Environment  $target  Destination environment in the same project.
     * @param  User  $requester  User whose workspace membership and deployment permission are checked.
     * @param  string|null  $note  Optional approval context, bounded before storage.
     * @return BuildPromotionResult The promotion outcome and created build when the target accepts the request.
     */
    public function handle(Build $source, Environment $target, User $requester, ?string $note = null): BuildPromotionResult
    {
        $result = DB::transaction(function () use ($source, $target, $requester, $note): BuildPromotionResult {
            $lockedSource = Build::query()->with(['repository.provider', 'environment.project'])->lockForUpdate()->findOrFail($source->id);
            $lockedTarget = Environment::query()->with('project')->lockForUpdate()->findOrFail($target->id);

            if ((int) $requester->current_organization_id !== (int) $lockedTarget->project->organization_id
                || ! $lockedTarget->project->organization->permits($requester, 'deploy')
                || $lockedSource->status !== Build::STATUS_SUCCEEDED
                || ! preg_match('/\A[0-9a-f]{40,64}\z/D', (string) $lockedSource->revision)
                || ! $lockedSource->repository
                || ! $lockedSource->environment
                || $lockedSource->environment->project_id !== $lockedTarget->project_id
                || $lockedSource->environment_id === $lockedTarget->id
                || ! $this->movesForward($lockedSource->environment, $lockedTarget)) {
                return new BuildPromotionResult(BuildPromotionResult::INELIGIBLE);
            }

            if (! $lockedTarget->website_id) {
                return new BuildPromotionResult(BuildPromotionResult::INCOMPATIBLE);
            }
            // Match normal deployment lock order: website before repository.
            $website = Website::query()->lockForUpdate()->findOrFail($lockedTarget->website_id);
            if ($website->hasActiveDeployment()) {
                return new BuildPromotionResult(BuildPromotionResult::ACTIVE);
            }

            $targetRepository = Repository::query()
                ->with(['provider', 'website.server'])
                ->where('organization_id', $lockedTarget->project->organization_id)
                ->where('website_id', $lockedTarget->website_id)
                ->where('branch', $lockedTarget->branch)
                ->lockForUpdate()
                ->first();
            if (! $targetRepository || ! $this->sameSource($lockedSource->repository, $targetRepository)) {
                return new BuildPromotionResult(BuildPromotionResult::INCOMPATIBLE);
            }
            if (! $targetRepository->isDeploymentReady()) {
                return new BuildPromotionResult(BuildPromotionResult::UNAVAILABLE);
            }
            if ($lockedTarget->deploymentBlockReason()) {
                return new BuildPromotionResult(BuildPromotionResult::BLOCKED);
            }

            $targetRepository->update(['setup_stage' => 0]);
            $build = $targetRepository->builds()->create([
                'trigger_source' => Build::TRIGGER_PROMOTION,
                'revision' => $lockedSource->revision,
                'commit_message' => $lockedSource->commit_message,
                'promoted_from_build_id' => $lockedSource->id,
                'promotion_note' => filled($note) ? Str::limit(trim($note), 2000, '') : null,
                ...$this->deployments->attributesForEnvironment($targetRepository, $lockedTarget, $requester),
            ]);

            return new BuildPromotionResult(BuildPromotionResult::QUEUED, $build);
        });

        if ($result->build) {
            $this->notifyApprovers($result->build);
            $this->deployments->dispatch($result->build);
        }

        return $result;
    }

    /**
     * Compare normalized repository URLs, provider types, and branches for promotion compatibility.
     *
     * @param  Repository  $source  Repository attached to the source build.
     * @param  Repository  $target  Repository configured on the target website.
     * @return bool Whether both repositories identify the same source and branch.
     */
    private function sameSource(Repository $source, Repository $target): bool
    {
        return strtolower(rtrim($source->url, '/')) === strtolower(rtrim($target->url, '/'))
            && $source->provider?->provider === $target->provider?->provider;
    }

    /**
     * Compare environment ranks from preview through development and staging to production.
     *
     * @param  Environment  $source  Environment containing the source build.
     * @param  Environment  $target  Environment requested as the next deployment destination.
     * @return bool Whether the target has a strictly higher promotion rank than the source.
     */
    private function movesForward(Environment $source, Environment $target): bool
    {
        $rank = ['preview' => 0, 'development' => 1, 'staging' => 2, 'production' => 3];

        return ($rank[$target->type] ?? -1) > ($rank[$source->type] ?? -1);
    }

    /**
     * Notify unique workspace owners and administrators when the build awaits approval and deployment notifications are enabled.
     *
     * @param  Build  $build  Build record whose persisted deployment state and relationships are used by this operation.
     */
    private function notifyApprovers(Build $build): void
    {
        if ($build->status !== Build::STATUS_AWAITING_APPROVAL) {
            return;
        }
        $build->loadMissing(['environment.project.organization.owner', 'promotedFrom.environment']);
        $organization = $build->environment?->project?->organization;
        if (! $organization || ! $organization->receivesNotification('deployment', 'approval')) {
            return;
        }
        $approvers = collect([$organization->owner])
            ->merge($organization->members()->wherePivotIn('role', ['admin'])->get())
            ->filter()->unique('id');
        $approvers->each(fn ($approver) => $approver->notify(new PromotionApprovalRequestedNotification(
            $build->id,
            $build->promotedFrom?->environment?->name ?? 'a lower environment',
            $build->environment?->name ?? 'the target environment',
        )));
    }
}
