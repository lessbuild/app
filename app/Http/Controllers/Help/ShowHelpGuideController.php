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
        $copy = config('help.guides.'.$guide);
        abort_unless(is_array($copy), 404);

        return response()->view('help.guide', [
            'slug' => $guide,
            'guide' => $copy,
            'group' => config('help.groups.'.$copy['group']),
            'related' => collect(config('help.guides'))->filter(fn (array $other, string $key): bool => $other['group'] === $copy['group'] && $key !== $guide),
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
