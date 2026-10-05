<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\Catalog\AddOn;
use App\Platform\Catalog\Meter;
use App\Platform\Catalog\Tier;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowPricingController
{
    /**
     * Show every service's tiers (monthly and yearly prices, in US cents), add-ons and metered usage from the
     * catalogue, the trial, and the comparison pages.
     *
     * @param  ServiceRegistry  $services
     * @return JsonResponse
     */
    public function __invoke(ServiceRegistry $services): JsonResponse
    {
        return response()->json([
            'meta' => PageMeta::for(__('Pricing: a free tier for every service'), __('Deploy, Monitoring, Security, Analytics and Audit each have a free tier, and Infrastructure is included. Pay for what each project needs, on one bill, with your apps on servers in your own cloud.'), route('pricing'), null, [
                StructuredData::breadcrumbs([config('app.name') => route('home'), __('Pricing') => route('pricing')]),
            ]),
            'trialDays' => (int) config('billing.trial_days'),
            'services' => array_map(fn (PlatformService $service): array => [
                'key' => $service->key(),
                'name' => $service->name(),
                'tagline' => $service->tagline(),
                'tiers' => array_map(fn (Tier $tier): array => [
                    'key' => $tier->key,
                    'name' => $tier->name,
                    'monthlyCents' => $tier->monthlyCents,
                    'yearlyCents' => $tier->yearlyCents(),
                    'description' => $tier->description,
                    'features' => $tier->features,
                ], $service->billing()->tiers),
                'addOns' => array_map(fn (AddOn $addOn): array => ['name' => $addOn->name, 'monthlyCentsPerUnit' => $addOn->monthlyCentsPerUnit, 'description' => $addOn->description], $service->billing()->addOns),
                'meters' => array_map(fn (Meter $meter): string => $meter->name, $service->billing()->meters),
            ], $services->all()),
            'competitors' => collect((array) config('compare.competitors'))->map(fn (array $competitor, string $slug): array => ['slug' => $slug, 'name' => $competitor['name']])->values(),
        ])->header('Vary', 'Accept-Language');
    }
}
