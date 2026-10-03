<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Models\User;
use App\Support\Changelog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ShowChangelogController
{
    /**
     * Show what's new, newest first: cached publicly for five minutes for visitors; for signed-in people it also
     * marks everything as seen, clearing the app's "What's new" dot.
     *
     * @param  Request  $request
     * @return Response
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        if ($user instanceof User && Changelog::unseen($user->last_seen_changelog_at?->format('Y-m-d')) > 0) {
            $user->forceFill(['last_seen_changelog_at' => Changelog::latestDate()])->save();
        }

        return response()->view('site.changelog', ['entries' => Changelog::entries()])->header('Cache-Control', $user === null ? 'public, max-age=300' : 'private, no-store');
    }
}
