<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Services\StatusPageReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

class PublicStatusPageController extends Controller
{
    public function show(StatusPage $statusPage, StatusPageReport $report): Response|RedirectResponse
    {
        abort_unless($statusPage->published, 404);

        if (config('platform.products.monitor.enabled', false) && Route::has('core.status-pages.show')) {
            return redirect()->to(route('core.status-pages.show', [
                'product' => 'monitor',
                'slug' => $statusPage->slug,
            ]), 301);
        }

        return response()->view('monitor::status-pages.public', [
            'statusPage' => $statusPage,
            ...$report->report($statusPage),
        ])->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
    }
}
