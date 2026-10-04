<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowSiteFrameController
{
    /**
     * Describe the public site's frame: the services for its navigation (with what each is for and a line about it, for
     * the services menu), the one-line summary, the contact address, and whether the visitor is signed in (for the
     * header's buttons).
     *
     * @param  Request  $request
     * @param  ServiceRegistry  $services
     * @return JsonResponse
     */
    public function __invoke(Request $request, ServiceRegistry $services): JsonResponse
    {
        return response()->json([
            'services' => array_map(fn (PlatformService $service): array => [
                'key' => $service->key(),
                'name' => $service->name(),
                'icon' => (string) config('marketing.services.'.$service->key().'.icon', $service->icon()),
                'accent' => (string) config('marketing.services.'.$service->key().'.accent', $service->key()),
                'eyebrow' => __((string) config('marketing.services.'.$service->key().'.eyebrow', '')),
                'summary' => __((string) config('marketing.services.'.$service->key().'.card_summary', '')),
            ], $services->all()),
            'summary' => __((string) config('marketing.summary')),
            'contactEmail' => (string) config('legal.contact_email'),
            'signedIn' => $request->user() !== null,
        ])->header('Vary', 'Cookie, Accept-Language');
    }
}
