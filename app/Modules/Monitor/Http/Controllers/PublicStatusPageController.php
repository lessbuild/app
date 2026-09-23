<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Services\StatusPageReport;
use Illuminate\Http\Response;

class PublicStatusPageController extends Controller
{
    public function show(StatusPage $statusPage, StatusPageReport $report): Response
    {
        abort_unless($statusPage->published, 404);

        return response()->view('monitor::status-pages.public', [
            'statusPage' => $statusPage,
            ...$report->report($statusPage),
        ])->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
    }
}
