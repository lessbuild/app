<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Admin\SystemHealth;
use Illuminate\Http\Response;

final class ShowHealthController
{
    /**
     * Show the platform's health checks, queue backlogs and retention jobs, freshly run and never cached.
     *
     * @param  SystemHealth  $health
     * @return Response
     */
    public function __invoke(SystemHealth $health): Response
    {
        return response()->view('admin.health', ['checks' => $health->checks(), 'queues' => $health->queues(), 'retention' => $health->retention()])->header('Cache-Control', 'no-store, private');
    }
}
