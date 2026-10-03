<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\Admin\PlatformStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class ShowPlatformStatusController
{
    /**
     * Show the platform's status page: the operator's own Monitoring status page when one is configured, otherwise the
     * coarse platform status, cached publicly for 30 seconds.
     *
     * @param  PlatformStatus  $status
     * @return Response|RedirectResponse
     */
    public function __invoke(PlatformStatus $status): Response|RedirectResponse
    {
        $page = config('platform.status_page');
        if (is_string($page) && $page !== '') {
            return to_route('status.show', $page);
        }

        return response()->view('status-pages.platform', ['snapshot' => $status->snapshot()])
            ->header('Cache-Control', 'public, max-age=30')->header('X-Content-Type-Options', 'nosniff');
    }
}
