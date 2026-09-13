<?php

namespace App\Services;

use App\Data\DeploymentTimelineEntry;
use App\Models\Build;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\ConfigureResourcesScript;
use App\Scripts\Repository\ConfigureWebRuntimeScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use App\Scripts\Repository\RunBuildCommandsScript;
use App\Scripts\Repository\SyncEnvironmentScript;
use App\Scripts\Repository\VerifyDeploymentHealthScript;
use Carbon\CarbonInterface;

class BuildDeploymentTimeline
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_PENDING = 'pending';

    public function __construct(private readonly RepositoryDeploymentPlan $plan) {}

    /**
     * Assemble the user-facing lifecycle from the existing callback stages.
     *
     * A build callback records the number of completed stages, not a timestamp
     * for each stage. This reader therefore reports milestone state and only
     * attaches timestamps that the build actually persists.
     *
     * @return list<DeploymentTimelineEntry> Ordered deployment milestones.
     */
    public function for(Build $build): array
    {
        $entries = [new DeploymentTimelineEntry(
            key: 'requested',
            title: 'Deployment requested',
            description: 'The deployment request and its immutable revision and environment snapshot were recorded.',
            status: self::STATUS_COMPLETED,
            occurredAt: $build->created_at,
        )];

        $previousStage = 0;
        $progress = $this->progress($build);
        $finalStage = $this->plan->finalStage();

        foreach ($this->milestones() as $milestone) {
            $stage = $this->plan->stageFor($milestone['script']);
            if ($stage === null) {
                continue;
            }

            $status = $this->statusFor($build, $stage, $previousStage, $progress, $finalStage);
            $entries[] = new DeploymentTimelineEntry(
                key: $milestone['key'],
                title: $milestone['title'],
                description: $milestone['description'],
                status: $status,
                occurredAt: $this->occurredAt($build, $milestone['key'], $status),
            );
            $previousStage = $stage;
        }

        return $this->withApproval($build, $entries);
    }

    /**
     * Define product milestones in terms of the current deployment plan rather than hard-coded stage numbers.
     *
     * @return list<array{key: string, title: string, description: string, script: class-string}> Milestone definitions.
     */
    private function milestones(): array
    {
        return [
            [
                'key' => 'provisioning',
                'title' => 'Prepare deployment',
                'description' => 'Clone and check out the revision, then apply the captured environment configuration.',
                'script' => SyncEnvironmentScript::class,
            ],
            [
                'key' => 'build',
                'title' => 'Build application',
                'description' => 'Install dependencies and run the configured build commands.',
                'script' => RunBuildCommandsScript::class,
            ],
            [
                'key' => 'migration',
                'title' => 'Prepare application',
                'description' => 'Run framework preparation and migrations when the repository supports them.',
                'script' => ArtisanCommandsScript::class,
            ],
            [
                'key' => 'release',
                'title' => 'Activate release',
                'description' => 'Activate the candidate release and retain the previous release for recovery.',
                'script' => ActivateReleaseScript::class,
            ],
            [
                'key' => 'traffic',
                'title' => 'Route traffic',
                'description' => 'Start the candidate runtime and route traffic after its readiness check.',
                'script' => ConfigureWebRuntimeScript::class,
            ],
            [
                'key' => 'resources',
                'title' => 'Configure managed resources',
                'description' => 'Apply the captured managed-resource definitions for this environment.',
                'script' => ConfigureResourcesScript::class,
            ],
            [
                'key' => 'health',
                'title' => 'Verify deployment health',
                'description' => 'Run the configured post-deployment health check and preserve rollback context on failure.',
                'script' => VerifyDeploymentHealthScript::class,
            ],
            [
                'key' => 'finalization',
                'title' => 'Finalize deployment',
                'description' => 'Complete post-deployment commands and remove releases outside the retention window.',
                'script' => PurgeOldReleasesScript::class,
            ],
        ];
    }

    /**
     * Normalize persisted progress for display while treating a successful build as complete for legacy records.
     */
    private function progress(Build $build): int
    {
        $finalStage = $this->plan->finalStage();

        if ($build->status === Build::STATUS_SUCCEEDED) {
            return $finalStage;
        }

        return max(0, min($finalStage, (int) $build->setup_stage));
    }

    /**
     * Resolve a milestone state from its range of callback stages without inventing per-stage timestamps.
     */
    private function statusFor(Build $build, int $stage, int $previousStage, int $progress, int $finalStage): string
    {
        if ($build->status === Build::STATUS_FAILED && $progress >= $finalStage && $stage === $finalStage) {
            return self::STATUS_FAILED;
        }

        if ($progress >= $stage) {
            return self::STATUS_COMPLETED;
        }

        if (in_array($build->status, [Build::STATUS_CANCELED, Build::STATUS_REJECTED], true)) {
            return self::STATUS_CANCELED;
        }

        $nextStage = min($finalStage, $progress + 1);
        if ($nextStage <= $previousStage || $nextStage > $stage) {
            return self::STATUS_PENDING;
        }

        return match ($build->status) {
            Build::STATUS_FAILED => self::STATUS_FAILED,
            Build::STATUS_DEPLOYING, Build::STATUS_RUNNING, Build::STATUS_TIMING_OUT => self::STATUS_ACTIVE,
            default => self::STATUS_PENDING,
        };
    }

    /**
     * Attach only timestamps whose meaning is already persisted by the deployment lifecycle.
     */
    private function occurredAt(Build $build, string $key, string $status): ?CarbonInterface
    {
        if ($key === 'release' && $status === self::STATUS_COMPLETED) {
            return $build->activated_at;
        }

        if ($key === 'finalization' && in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true)) {
            return $build->finished_at;
        }

        return null;
    }

    /**
     * Add an approval milestone only when approval is part of this build's persisted workflow.
     *
     * @param  list<DeploymentTimelineEntry>  $entries  Already assembled request and execution milestones.
     * @return list<DeploymentTimelineEntry> Entries with approval inserted after the request when applicable.
     */
    private function withApproval(Build $build, array $entries): array
    {
        $environment = $build->relationLoaded('environment') ? $build->getRelation('environment') : null;
        $approvalRequired = $environment?->requires_deployment_approval === true
            || $build->approved_at !== null
            || $build->rejected_at !== null
            || $build->status === Build::STATUS_REJECTED;
        if (! $approvalRequired) {
            return $entries;
        }

        $approved = $build->approved_at !== null;
        $rejected = $build->rejected_at !== null || $build->status === Build::STATUS_REJECTED;
        $status = $approved
            ? self::STATUS_COMPLETED
            : ($rejected ? self::STATUS_CANCELED : ($build->status === Build::STATUS_AWAITING_APPROVAL ? self::STATUS_ACTIVE : self::STATUS_PENDING));
        $description = $approved
            ? 'A permitted reviewer approved this deployment request.'
            : ($rejected ? 'The deployment request was declined before remote execution.' : 'A permitted reviewer must approve this deployment before remote execution.');

        array_splice($entries, 1, 0, [new DeploymentTimelineEntry(
            key: 'approval',
            title: 'Deployment approval',
            description: $description,
            status: $status,
            occurredAt: $build->approved_at ?? $build->rejected_at,
        )]);

        return $entries;
    }
}
