<?php

declare(strict_types=1);

namespace App\Http\Controllers\WhatsNew;

use App\Models\User;
use App\Support\Changelog;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class MarkChangelogSeenController
{
    /**
     * Mark the changelog seen up to its latest entry, which clears the "what's new" dot.
     *
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        $user->forceFill(['last_seen_changelog_at' => Changelog::latestDate()])->save();

        return response()->json(['unseenChanges' => 0]);
    }
}
