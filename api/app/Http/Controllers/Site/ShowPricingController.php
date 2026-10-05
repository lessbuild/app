<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\Catalog\AddOn;
use App\Platform\Catalog\Meter;
use App\Platform\Catalog\Tier;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use App\Support\Site\PageMeta;
use App\Support\Site\PricingMatrix;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowPricingController
{
    /**
     * The tier suggested first for each service: the one that suits most production work.
     *
     * @var list<string>
     */
    private const RECOMMENDED = ['deploy.pro', 'monitoring.pro', 'analytics.pro', 'security.pro', 'audit.pro'];

    /**
     * Typical setups to start the stack builder from: a tier for each service, or null to leave it off. Infrastructure
     * comes with Deploy.
     *
     * @var list<array{key: string, name: string, description: string, icon: string, picks: array<string, string|null>}>
     */
    private const PRESETS = [
        ['key' => 'side-project', 'name' => 'Side project', 'description' => 'One app, everything on the free tiers.', 'icon' => 'leaf',
            'picks' => ['deploy' => 'free', 'infrastructure' => 'included', 'monitoring' => 'free', 'analytics' => 'free', 'security' => 'free', 'audit' => 'free']],
        ['key' => 'startup', 'name' => 'Startup', 'description' => 'A production app with previews and backups.', 'icon' => 'rocket',
            'picks' => ['deploy' => 'pro', 'infrastructure' => 'included', 'monitoring' => 'pro', 'analytics' => 'pro', 'security' => 'free', 'audit' => 'free']],
        ['key' => 'agency', 'name' => 'Agency', 'description' => 'Many client sites, with reports in your name.', 'icon' => 'people',
            'picks' => ['deploy' => 'team', 'infrastructure' => 'included', 'monitoring' => 'pro', 'analytics' => 'business', 'security' => 'pro', 'audit' => 'business']],
        ['key' => 'scale', 'name' => 'Scale', 'description' => 'A big fleet, on-call and auditors to answer to.', 'icon' => 'layers',
            'picks' => ['deploy' => 'business', 'infrastructure' => 'included', 'monitoring' => 'team', 'analytics' => 'business', 'security' => 'team', 'audit' => 'pro']],
    ];

    /**
     * Show every service's tiers (monthly and yearly prices, in US cents), add-ons, metered usage with each tier's
     * allowance, the key features table read from the tiers' limits and flags, typical setups to start from, the trial,
     * and the comparison pages.
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
                    'recommended' => in_array($service->key().'.'.$tier->key, self::RECOMMENDED, true),
                ], $service->billing()->tiers),
                'matrix' => PricingMatrix::for($service->key(), $service->billing()->tiers),
                'addOns' => array_map(fn (AddOn $addOn): array => ['name' => $addOn->name, 'monthlyCentsPerUnit' => $addOn->monthlyCentsPerUnit, 'description' => $addOn->description], $service->billing()->addOns),
                'meters' => array_map(fn (Meter $meter): array => [
                    'name' => $meter->name,
                    'unit' => $meter->unit,
                    'unitSize' => $meter->unitSize,
                    'unitCents' => $meter->unitCents,
                    'allowances' => array_map(fn (Tier $tier): ?int => isset($tier->limits[$meter->allowanceKey]) ? (int) $tier->limits[$meter->allowanceKey] : null, $service->billing()->tiers),
                ], $service->billing()->meters),
            ], $services->all()),
            'presets' => array_map(fn (array $preset): array => [...$preset, 'name' => __($preset['name']), 'description' => __($preset['description'])], self::PRESETS),
            'competitors' => collect((array) config('compare.competitors'))->map(fn (array $competitor, string $slug): array => ['slug' => $slug, 'name' => $competitor['name'], 'what' => __((string) ($competitor['what'] ?? ''))])->values(),
        ])->header('Vary', 'Accept-Language');
    }
}
