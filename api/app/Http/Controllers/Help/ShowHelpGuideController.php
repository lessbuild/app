<?php

declare(strict_types=1);

namespace App\Http\Controllers\Help;

use Illuminate\Http\Response;

final class ShowHelpGuideController
{
    /**
     * Show one help guide with the others in its group beside it, cached publicly for five minutes.
     *
     * @param  string  $guide
     * @return Response
     */
    public function __invoke(string $guide): Response
    {
        /** @var array<string, array{group: string, title: string, summary: string, steps: list<array{string, string}>}> $guides */
        $guides = (array) config('help.guides');
        $copy = $guides[$guide] ?? abort(404);

        return response()->view('help.guide', [
            'slug' => $guide,
            'guide' => $copy,
            'group' => config('help.groups.'.$copy['group']),
            'related' => collect($guides)->filter(fn (array $other, string $key): bool => $other['group'] === $copy['group'] && $key !== $guide),
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
