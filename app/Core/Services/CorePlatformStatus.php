<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class CorePlatformStatus
{
    private const CACHE_KEY = 'buildpusher:core-platform-status:v2';

    public function __construct(private readonly PlatformStatusProviderRegistry $providers) {}

    /**
     * @return array{status: string, operational: bool, checked_at: string, components: list<array{name: string, description: string, status: string, operational: bool}>}
     */
    public function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addSeconds(30), fn (): array => $this->buildSnapshot());
    }

    /**
     * @return array{status: string, operational: bool, checked_at: string, components: list<array{name: string, description: string, status: string, operational: bool}>}
     */
    private function buildSnapshot(): array
    {
        $components = [];

        foreach (config('platform.products', []) as $product => $settings) {
            if (! is_array($settings) || ! ($settings['enabled'] ?? false)) {
                continue;
            }

            $label = is_string($settings['label'] ?? null) && $settings['label'] !== ''
                ? $settings['label']
                : Str::headline((string) $product);
            $provider = $this->providers->get((string) $product);

            try {
                $productComponents = $provider?->components() ?? [];
                if ($productComponents === []) {
                    $components[] = $this->unavailableComponent($label);

                    continue;
                }

                foreach ($productComponents as $component) {
                    $name = is_string($component['name'] ?? null) && $component['name'] !== ''
                        ? $component['name']
                        : __('Application');
                    $operational = (bool) ($component['operational'] ?? false);

                    $components[] = [
                        'name' => $label.' · '.$name,
                        'description' => is_string($component['description'] ?? null)
                            ? $component['description']
                            : __('Current service status could not be confirmed.'),
                        'status' => $operational ? __('Operational') : __('Degraded'),
                        'operational' => $operational,
                    ];
                }
            } catch (Throwable $exception) {
                report($exception);
                $components[] = $this->unavailableComponent($label);
            }
        }

        if ($components === []) {
            $components[] = $this->unavailableComponent(config('app.name', 'Buildpusher'));
        }

        $operational = collect($components)->every('operational');

        return [
            'status' => $operational ? 'operational' : 'degraded',
            'operational' => $operational,
            'checked_at' => now()->utc()->toIso8601String(),
            'components' => $components,
        ];
    }

    /** @return array{name: string, description: string, status: string, operational: bool} */
    private function unavailableComponent(string $label): array
    {
        return [
            'name' => $label.' · '.__('Application'),
            'description' => __('Current service status could not be confirmed.'),
            'status' => __('Unavailable'),
            'operational' => false,
        ];
    }
}
