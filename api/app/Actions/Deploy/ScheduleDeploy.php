<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Repository;
use App\Models\ScheduledDeploy;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\GitRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ScheduleDeploy
{
    /**
     * Create a new ScheduleDeploy instance.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes scheduled deploys.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Book a deploy of the repository (or a branch, tag or commit) for a time in the next 30 days. It runs as the
     * person who booked it, and the environment's approvals, locks, windows and freezes apply when it runs.
     *
     * @param  User  $actor
     * @param  Repository  $repository
     * @param  CarbonImmutable  $runAt
     * @param  string|null  $ref
     * @return ScheduledDeploy
     */
    public function handle(User $actor, Repository $repository, CarbonImmutable $runAt, ?string $ref = null): ScheduledDeploy
    {
        Gate::forUser($actor)->authorize('deploy', $repository);
        if (! $this->entitlements->for($repository->project->account)->has('deploy.scheduled')) {
            throw ValidationException::withMessages(['deploy_at' => __('Scheduled deploys come with the Pro Deploy plan and above.')]);
        }
        if ($runAt->isPast() || $runAt->greaterThan(now()->addDays(30))) {
            throw ValidationException::withMessages(['deploy_at' => __('Choose a time in the next 30 days.')]);
        }
        $gitRef = null;
        if ($ref !== null && trim($ref) !== '') {
            $gitRef = GitRef::normalize($ref) ?? throw ValidationException::withMessages(['ref' => __('Enter a branch, tag or commit, such as main, v1.2.0 or 3f2a9c1.')]);
        }
        $scheduled = new ScheduledDeploy;
        $scheduled->forceFill([
            'repository_id' => $repository->id, 'run_at' => $runAt->utc()->startOfMinute(), 'git_ref' => $gitRef,
            'status' => ScheduledDeploy::STATUS_PENDING, 'created_by' => $actor->id,
        ])->save();

        return $scheduled;
    }
}
