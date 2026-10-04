<?php

declare(strict_types=1);

namespace App\Http\Controllers\Help;

use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowHelpGuideController
{
    /**
     * Show a help guide's steps and the other guides in its group. Unknown guides are a 404.
     *
     * @param  string  $guide
     * @return JsonResponse
     */
    public function __invoke(string $guide): JsonResponse
    {
        $guides = (array) config('help.guides');
        $copy = $guides[$guide] ?? abort(404);
        $group = (array) config('help.groups.'.$copy['group']);
        $title = __($copy['title']);
        $summary = __($copy['summary']);

        return response()->json([
            'meta' => PageMeta::for($title, $summary, route('help.guide', $guide), null, [
                StructuredData::breadcrumbs([config('app.name') => route('home'), __('Help centre') => route('help'), $title => route('help.guide', $guide)]),
                StructuredData::article($title, $summary, route('help.guide', $guide)),
            ]),
            'slug' => $guide,
            'title' => $title,
            'summary' => $summary,
            'group' => __((string) ($group['title'] ?? '')),
            'steps' => array_map(fn (array $step): array => ['title' => __($step[0]), 'text' => __($step[1])], $copy['steps']),
            'related' => collect($guides)->filter(fn (array $other, string $key): bool => $other['group'] === $copy['group'] && $key !== $guide)
                ->map(fn (array $other, string $key): array => ['slug' => $key, 'title' => __($other['title'])])->values(),
            'contactEmail' => (string) config('legal.contact_email'),
        ])->header('Vary', 'Accept-Language');
    }
}
