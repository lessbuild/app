<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Preview;
use App\Models\User;
use App\Services\Deploy\Previews;
use Illuminate\Support\Facades\Gate;

final class ClosePreview
{
    /**
     * Create a new ClosePreview instance.
     *
     * Closes previews on request.
     *
     * @param  Previews  $previews  Closes the preview and removes its stack.
     */
    public function __construct(private readonly Previews $previews) {}

    /**
     * Close an open preview before its pull request closes; its stack is removed. A new revision pushed to the pull
     * request opens it again.
     *
     * @param  User  $actor
     * @param  Preview  $preview
     * @return void
     */
    public function handle(User $actor, Preview $preview): void
    {
        Gate::forUser($actor)->authorize('operate', $preview);
        $this->previews->close($preview);
    }
}
