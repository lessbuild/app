<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Changelog;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class MarkChangelogSeenController
{
    /**
     * Mark everything in the changelog as seen, clearing the "What's new" dot, and go back.
     *
     * @param  User  $user
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user): RedirectResponse
    {
        $user->forceFill(['last_seen_changelog_at' => Changelog::latestDate()])->save();

        return back();
    }
}
