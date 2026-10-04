<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use App\Support\Site\Copy;
use App\Support\Site\PageMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowHomeController
{
    /**
     * Show the home page's copy: the hero, a card and an explorer panel per service, how the pieces fit, and why one
     * platform. Signed-in people are sent to their dashboard instead.
     *
     * @param  Request  $request
     * @param  ServiceRegistry  $services
     * @return JsonResponse
     */
    public function __invoke(Request $request, ServiceRegistry $services): JsonResponse
    {
        if ($request->user() !== null) {
            return response()->json(['redirect' => route('dashboard', [], false)]);
        }
        $summary = __((string) config('marketing.summary'));

        return response()->json([
            'meta' => PageMeta::for(__('Deploy, monitor and analyse apps on your own servers'), $summary, route('home'), null, [[
                '@type' => 'SoftwareApplication', 'name' => config('app.name'), 'url' => route('home'), 'applicationCategory' => 'DeveloperApplication', 'operatingSystem' => 'Web',
                'description' => $summary, 'publisher' => ['@id' => route('home').'#organization'],
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD', 'description' => __('Free tier for every service')],
            ]]),
            'summary' => $summary,
            'hero' => Copy::translate((array) config('marketing.hero')),
            'services' => array_map(fn (PlatformService $service): array => [
                'key' => $service->key(),
                'name' => $service->name(),
                'copy' => Copy::translate((array) config('marketing.services.'.$service->key())),
            ], $services->all()),
            'workflow' => array_map(fn (array $step): array => ['number' => $step[0], 'title' => __($step[1]), 'text' => __($step[2]), 'icon' => $step[3]], (array) config('marketing.workflow')),
            'integrations' => array_map(fn (array $pair): array => ['title' => __($pair[0]), 'text' => __($pair[1])], (array) config('marketing.integrations')),
        ])->header('Vary', 'Cookie, Accept-Language');
    }
}
