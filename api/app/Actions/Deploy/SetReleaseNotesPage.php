<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class SetReleaseNotesPage
{
    /**
     * Publish the environment's release notes at a public address that can't be guessed, or take them down (the
     * address stops working; publishing again makes a new one).
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  bool  $published
     * @return void
     */
    public function handle(User $actor, Environment $environment, bool $published): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $environment->forceFill(['release_notes_token' => $published ? ($environment->release_notes_token ?? Str::lower(Str::random(32))) : null])->save();
    }
}
