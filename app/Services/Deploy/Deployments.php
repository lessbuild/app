<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Jobs\Deploy\PublishBuild;
use App\Models\Build;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

/**
 * Queues builds: one active build per website (a second deploy is refused), with the repository's settings captured in
 * the build, and a wait for approval when its environment asks for one.
 */
class Deployments
{
    public function __construct(private readonly BuildPayload $payload) {}

    /**
     * @param  array<string, mixed>  $attributes  trigger_source, revision, commit_message, …
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
            }

            return $build;
        });
    }
}
