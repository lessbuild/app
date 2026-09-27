<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\Build;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Deploy\Deployments;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RollbackBuild
{
    public function __construct(private readonly Entitlements $entitlements, private readonly Deployments $deployments) {}

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

        return $this->deployments->rollback($source, $actor) ?? throw new StateConflict(__('A deploy to this website is already running.'));
    }
}
