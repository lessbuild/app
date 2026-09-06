<?php

namespace App\Http\Controllers;

use App\Services\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SystemHealthController extends Controller
{
    public function __invoke(Request $request, SystemHealth $systemHealth): Response
    {
        $this->authorizeAccess($request);
        $snapshot = $systemHealth->fresh();

        return response()->view('system-health.index', [
            'checks' => $snapshot['checks'],
            'passed' => $snapshot['passed'],
            'passedCount' => $snapshot['passed_count'],
            'checkedAt' => $snapshot['checked_at'],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function report(Request $request, SystemHealth $systemHealth): JsonResponse
    {
        $this->authorizeAccess($request);
        $snapshot = $systemHealth->fresh();
        $filename = 'lessbuild-system-health-'.$snapshot['checked_at']->utc()->format('Ymd-His').'.json';

        return response()->json([
            'status' => $snapshot['passed'] ? 'ready' : 'failed',
            'generated_at' => $snapshot['checked_at']->toIso8601String(),
            'summary' => [
                'passed' => $snapshot['passed_count'],
                'total' => count($snapshot['checks']),
            ],
            'checks' => $snapshot['checks'],
        ], headers: [
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeAccess(Request $request): void
    {
        $organization = $request->user()->currentOrganization;

        abort_unless($organization && $organization->permits($request->user(), 'manage'), 403);
    }
}
