<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ExportPlatformAccountData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformAccountDataExportController
{
    public function __invoke(Request $request, ExportPlatformAccountData $export): JsonResponse
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return response()->json($export->handle($user), headers: [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Disposition' => 'attachment; filename="buildpusher-account-export-'.now()->utc()->format('Ymd-His').'.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
