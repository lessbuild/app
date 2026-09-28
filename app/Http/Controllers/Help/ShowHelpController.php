<?php

declare(strict_types=1);

namespace App\Http\Controllers\Help;

use Illuminate\Http\Response;

final class ShowHelpController
{
    /**
     * Show the help centre: every guide, grouped by service, cached publicly for five minutes.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        return response()->view('help.index', ['groups' => config('help.groups'), 'guides' => collect(config('help.guides'))->groupBy('group', preserveKeys: true)])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
