<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\Build;
use App\Models\User;
use App\Services\Deploy\RemoteDeployments;
use Illuminate\Support\Facades\Gate;

final class CancelBuild
{
    /**
     * Cancels a deploy that hasn't finished.
     *
     * @param  FinishBuild  $finish  Marks the deploy canceled.
     * @param  RemoteDeployments  $remote  Stops the script on the server when it's already running.
     */
    public function __construct(private readonly FinishBuild $finish, private readonly RemoteDeployments $remote) {}

    /**
     * Stop a build: a waiting one is simply dropped; a running one's script is killed on the server (keeping its log).
     *
     * @param  User  $actor
     * @param  Build  $build
     * @return void
     */
    public function handle(User $actor, Build $build): void
    {
        Gate::forUser($actor)->authorize('deploy', $build->repository);
        StateConflict::unless($build->isActive(), __('This deploy has already finished.'));
        $log = $build->status === Build::STATUS_RUNNING ? $this->remote->stop($build) : null;
        $this->finish->handle($build, Build::STATUS_CANCELED, null, $log);
    }
}
