<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Jobs\Deploy\SwitchRelease;
use App\Models\Build;
use App\Models\User;
use App\Models\Website;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RollbackBuild
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** Make an earlier succeeded release live again without rebuilding (its directory must still be on the server). */
    public function handle(User $actor, Build $source): Build
    {
        Gate::forUser($actor)->authorize('deploy', $source->repository);
        if (! $this->entitlements->for($source->website->account)->has('deploy.releases')) {
            throw ValidationException::withMessages(['rollback' => __('Rollbacks come with release history on your Deploy plan.')]);
        }
        if ($source->status !== Build::STATUS_SUCCEEDED || $source->release_name === null || $source->release_path === null) {
            throw ValidationException::withMessages(['rollback' => __('Only a succeeded release can be made live again.')]);
        }

        return DB::transaction(function () use ($actor, $source): Build {
            Website::query()->lockForUpdate()->findOrFail($source->website_id);
            StateConflict::unless(! Build::query()->where('website_id', $source->website_id)->whereIn('status', Build::ACTIVE)->exists(), __('A deploy to this website is already running.'));
            $build = new Build;
            $build->forceFill([
                'repository_id' => $source->repository_id, 'website_id' => $source->website_id, 'environment_id' => $source->environment_id,
                'requested_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(), 'status' => Build::STATUS_QUEUED, 'trigger_source' => 'rollback',
                'revision' => $source->revision, 'commit_message' => $source->commit_message, 'release_name' => $source->release_name,
                'release_path' => $source->release_path, 'environment_payload' => ['repository_root' => $source->deploymentRoot()], 'rolled_back_from_build_id' => $source->id,
            ])->save();
            SwitchRelease::dispatch($build->id)->afterCommit();

            return $build;
        });
    }
}
