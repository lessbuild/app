<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\Build;
use App\Models\Preview;
use App\Models\Repository;
use App\Models\User;
use App\Services\Deploy\Previews;
use Illuminate\Support\Facades\Gate;

final class DeleteRepository
{
    /**
     * Create a new DeleteRepository instance.
     *
     * Disconnects repositories.
     *
     * @param  Previews  $previews  Closes the previews of the repository's pull requests.
     */
    public function __construct(private readonly Previews $previews) {}

    /**
     * Stop deploying from a repository and close its pull requests' previews. Its builds stay in the history; the
     * website and its releases aren't touched. A preview's own repository goes with its preview instead.
     *
     * @param  User  $actor
     * @param  Repository  $repository
     * @return void
     */
    public function handle(User $actor, Repository $repository): void
    {
        Gate::forUser($actor)->authorize('delete', $repository);
        StateConflict::unless(! $repository->preview()->exists(), __('This repository belongs to a preview. Close the preview instead.'));
        StateConflict::unless(! $repository->builds()->whereIn('status', Build::ACTIVE)->exists(), __('Wait for the running deploy to finish.'));
        $repository->forceFill(['webhook_enabled' => false])->save();
        $repository->delete();
        $repository->previews()->where('status', '!=', Preview::STATUS_CLOSED)->get()->each(fn (Preview $preview) => $this->previews->close($preview));
    }
}
