<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\Preview;
use App\Models\User;
use App\Services\Deploy\Previews;
use Illuminate\Support\Facades\Gate;

final class RetryPreviewCleanup
{
    /**
     * Create a new RetryPreviewCleanup instance.
     *
     * Retries failed preview cleanups.
     *
     * @param  Previews  $previews  Queues the cleanup again.
     */
    public function __construct(private readonly Previews $previews) {}

    /**
     * Queue a closed preview's failed cleanup again.
     *
     * @param  User  $actor
     * @param  Preview  $preview
     * @return void
     */
    public function handle(User $actor, Preview $preview): void
    {
        Gate::forUser($actor)->authorize('operate', $preview);
        StateConflict::unless(! $preview->isOpen() && $preview->cleanup_status === Preview::CLEANUP_FAILED, __('Only a failed cleanup can be retried.'));
        StateConflict::unless($this->previews->cleanUpWhenIdle($preview), __('Wait for the running deploy to finish.'));
    }
}
