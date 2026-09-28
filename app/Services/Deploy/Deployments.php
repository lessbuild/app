<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Jobs\Deploy\PublishBuild;
use App\Jobs\Deploy\SwitchRelease;
use App\Models\Build;
use App\Models\Membership;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Notifications\BuildAwaitingApproval;
use Illuminate\Support\Facades\DB;

/**
 * Queues builds: one active build per website (a second deploy is refused), with the repository's settings captured in
 * the build, and a wait for approval when its environment asks for one.
 */
class Deployments
{
    /**
     * Queues builds.
     *
     * @param  BuildPayload  $payload  Captures what each build deploys with.
     */
    public function __construct(private readonly BuildPayload $payload) {}

    /**
     * Why the repository's environment won't take a deploy now (locked, or outside its window), or null.
     *
     * @param  Repository  $repository
     * @return string|null
     */
    public function blockReason(Repository $repository): ?string
    {
        return $repository->environment?->deploymentBlockReason();
    }

    /**
     * Queue a rollback build that makes a retained release live again. It skips approval: it's putting back a release
     * that was already approved and live.
     *
     * @param  Build  $source
     * @param  User|null  $requester
     * @return Build|null
     */
    public function rollback(Build $source, ?User $requester): ?Build
    {
        return DB::transaction(function () use ($source, $requester): ?Build {
            Website::query()->lockForUpdate()->findOrFail($source->website_id);
            if (Build::query()->where('website_id', $source->website_id)->whereIn('status', Build::ACTIVE)->exists()) {
                return null;
            }
            $build = new Build;
            $build->forceFill([
                'repository_id' => $source->repository_id, 'website_id' => $source->website_id, 'environment_id' => $source->environment_id,
                'requested_by' => $requester?->id, 'approved_by' => $requester?->id, 'approved_at' => now(), 'status' => Build::STATUS_QUEUED, 'trigger_source' => 'rollback',
                'revision' => $source->revision, 'commit_message' => $source->commit_message, 'release_name' => $source->release_name,
                'release_path' => $source->release_path, 'environment_payload' => ['repository_root' => $source->deploymentRoot()], 'rolled_back_from_build_id' => $source->id,
            ])->save();
            SwitchRelease::dispatch($build->id)->afterCommit();

            return $build;
        });
    }

    /**
     * Queues a build for the repository unless its website already has one active (null then). The build waits for
     * approval when its environment requires it, in which case the approvers are notified after commit; otherwise it's
     * published after commit.
     *
     * @param  Repository  $repository
     * @param  array<string, mixed>  $attributes  trigger_source, revision, commit_message, …
     * @param  User|null  $requester
     * @return Build|null null when the website already has an active build
     */
    public function queue(Repository $repository, array $attributes, ?User $requester = null): ?Build
    {
        return DB::transaction(function () use ($repository, $attributes, $requester): ?Build {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            if (Build::query()->where('website_id', $website->id)->whereIn('status', Build::ACTIVE)->exists()) {
                return null;
            }
            $repository->setRelation('website', $website);
            $build = new Build;
            $build->forceFill([
                'repository_id' => $repository->id, 'website_id' => $website->id, 'environment_id' => $repository->environment_id,
                'requested_by' => $requester?->id, 'environment_payload' => $this->payload->for($repository),
                'status' => $repository->environment?->requires_deployment_approval === true ? Build::STATUS_AWAITING_APPROVAL : Build::STATUS_QUEUED,
                ...$attributes,
            ])->save();
            if ($build->status === Build::STATUS_QUEUED) {
                PublishBuild::dispatch($build->id)->afterCommit();
            } else {
                DB::afterCommit(fn () => $this->notifyApprovers($build));
            }

            return $build;
        });
    }

    /**
     * A deploy that failed after going live (or failed its observation) goes back to the last good release, if the environment asks for that.
     *
     * @param  Build  $failed
     * @return void
     */
    public function rollBackAutomatically(Build $failed): void
    {
        if ($failed->trigger_source === 'rollback' || $failed->environment?->automatic_rollback !== true) {
            return;
        }
        $source = Build::query()->where('repository_id', $failed->repository_id)->where('status', Build::STATUS_SUCCEEDED)->whereNotNull('release_name')
            ->whereKeyNot($failed->id)->where('id', '<', $failed->id)->latest('id')->first();
        $rollback = $source === null ? null : $this->rollback($source, $failed->requester);
        if ($rollback !== null) {
            $failed->forceFill(['automatic_rollback_build_id' => $rollback->id])->save();
        }
    }

    /**
     * Tell the people who could approve a waiting build (members with Deploy access, other than whoever asked for it).
     *
     * @param  Build  $build
     * @return void
     */
    private function notifyApprovers(Build $build): void
    {
        $build->loadMissing(['repository', 'website', 'environment', 'requester', 'promotedFrom.environment']);
        Membership::query()->where('account_id', $build->website->account_id)->with('user')->get()
            ->filter(fn (Membership $membership): bool => $membership->user_id !== $build->requested_by && $membership->user->can('approve', $build))
            ->each(fn (Membership $membership) => $membership->user->notify(new BuildAwaitingApproval($build)));
    }
}
