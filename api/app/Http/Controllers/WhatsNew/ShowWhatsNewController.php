<?php

declare(strict_types=1);

namespace App\Http\Controllers\WhatsNew;

use App\Support\Changelog;
use Illuminate\Http\JsonResponse;

final class ShowWhatsNewController
{
    /**
     * Show the three latest changelog entries (six changes each at most) for the app's "What's new" dialog. Reading
     * them doesn't mark them seen; "Got it" does.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'entries' => array_map(fn (array $entry): array => [
                'date' => $entry['date'],
                'title' => __($entry['title']),
                'changes' => array_map(fn (string $change): string => __($change), array_slice($entry['changes'], 0, 6)),
            ], array_slice(Changelog::entries(), 0, 3)),
        ]);
    }
}
