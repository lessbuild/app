<?php

namespace App\Modules\Monitor\Http\Middleware;

use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMonitorAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            MonitorDeletionFence::assertUserActive($userId);

            return $next($request);
        }

        return DB::connection('monitor')->transaction(function () use ($next, $request, $userId): Response {
            abort_if(MonitorDeletionFence::lockUser($userId), 410, 'This Monitor account is being deleted.');

            return $next($request);
        });
    }
}
