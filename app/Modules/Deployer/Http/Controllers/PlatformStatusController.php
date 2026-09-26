<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Services\PublicPlatformStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

class PlatformStatusController extends Controller
{
    /**
     * Render the public platform snapshot with a 30-second shared-cache lifetime.
     */
    public function show(PublicPlatformStatus $platformStatus): Response|RedirectResponse
    {
        if (Route::has('core.status')) {
            $coreStatusUrl = route('core.status');
            $coreOrigin = $this->origin($coreStatusUrl);
            $currentOrigin = $this->origin(request()->getSchemeAndHttpHost());

            if ($coreOrigin !== null && $currentOrigin !== null && $coreOrigin !== $currentOrigin) {
                return redirect()->to($coreStatusUrl, 301);
            }
        }

        return response()->view('status.platform', ['snapshot' => $platformStatus->snapshot()])
            ->header('Cache-Control', 'public, max-age=30')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Return the public platform snapshot as JSON with a 30-second shared-cache lifetime.
     */
    public function report(PublicPlatformStatus $platformStatus): JsonResponse
    {
        return response()->json($platformStatus->snapshot())
            ->header('Cache-Control', 'public, max-age=30')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $origin = $scheme.'://'.strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}
