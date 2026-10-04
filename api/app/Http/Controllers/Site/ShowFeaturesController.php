<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use App\Support\Site\Copy;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowFeaturesController
{
    /**
     * The services with their own link preview image (web/public/images/og).
     *
     * @var list<string>
     */
    private const PREVIEW_IMAGES = ['deploy', 'infrastructure', 'monitoring', 'security', 'analytics', 'audit'];

    /**
     * The services with a screenshot (web/public/images/screens).
     *
     * @var list<string>
     */
    private const SCREENSHOTS = ['deploy', 'infrastructure', 'monitoring', 'analytics'];

    /**
     * Show a service's page: everything it does, how it's used, its safeguards, how it works with the other services,
     * and common questions. Unknown services are a 404.
     *
     * @param  string  $service
     * @param  ServiceRegistry  $services
     * @return JsonResponse
     */
    public function __invoke(string $service, ServiceRegistry $services): JsonResponse
    {
        $definition = $services->find($service);
        $copy = config('marketing.services.'.$service);
        abort_if($definition === null || ! is_array($copy), 404);
        $copy = Copy::translate($copy);
        $questions = array_map(fn (array $pair): array => [(string) $pair[0], (string) $pair[1]], $copy['questions'] ?? []);

        return response()->json([
            'meta' => PageMeta::for(
                $definition->name().': '.$copy['eyebrow'],
                (string) $copy['summary'],
                route('features', $service),
                in_array($service, self::PREVIEW_IMAGES, true) ? $service.'.png' : null,
                array_values(array_filter([
                    StructuredData::breadcrumbs([config('app.name') => route('home'), $definition->name() => route('features', $service)]),
                    $questions !== [] ? StructuredData::faq($questions) : null,
                ])),
            ),
            'service' => ['key' => $definition->key(), 'name' => $definition->name()],
            'copy' => $copy,
            'screenshot' => in_array($service, self::SCREENSHOTS, true) ? '/images/screens/'.$service.'.png' : null,
            'others' => array_values(array_map(fn (PlatformService $other): array => [
                'key' => $other->key(),
                'name' => $other->name(),
                'copy' => Copy::translate(array_intersect_key((array) config('marketing.services.'.$other->key()), array_flip(['accent', 'icon', 'eyebrow', 'card_summary']))),
            ], array_filter($services->all(), fn (PlatformService $other): bool => $other->key() !== $service))),
        ])->header('Vary', 'Accept-Language');
    }
}
