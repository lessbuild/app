<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Models\User;
use App\Support\Changelog;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowChangelogController
{
    /**
     * Show every release, newest first. A signed-in person who hadn't seen the latest entries has them marked seen,
     * which clears the "what's new" dot in the app.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User && Changelog::unseen($user->last_seen_changelog_at?->format('Y-m-d')) > 0) {
            $user->forceFill(['last_seen_changelog_at' => Changelog::latestDate()])->save();
        }

        return response()->json([
            'meta' => PageMeta::for(
                __('Changelog'),
                __('Every :app release: new features and improvements to deploys, servers, monitoring, security and analytics, newest first.', ['app' => config('app.name')]),
                route('changelog'),
                null,
                [StructuredData::breadcrumbs([config('app.name') => route('home'), __('Changelog') => route('changelog')])],
            ),
            'entries' => array_map(fn (array $entry): array => [
                'date' => $entry['date'],
                'title' => __($entry['title']),
                'changes' => array_map(fn (string $change): string => __($change), $entry['changes']),
            ], Changelog::entries()),
        ])->header('Vary', 'Cookie, Accept-Language');
    }
}
