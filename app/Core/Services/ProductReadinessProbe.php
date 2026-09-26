<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

final class ProductReadinessProbe
{
    public function isReady(string $product, string $path, string $expectedStatus): bool
    {
        return collect($this->components($product, $path, $expectedStatus))
            ->every(static fn (array $component): bool => $component['operational']);
    }

    /**
     * @return list<array{name: string, description: string, operational: bool}>
     */
    public function components(string $product, string $path, string $expectedStatus): array
    {
        $baseUrl = config('platform.products.'.$product.'.url');
        $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! filled($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return [$this->unavailableComponent()];
        }

        try {
            $response = Http::connectTimeout(1)
                ->timeout(3)
                ->withoutRedirecting()
                ->acceptJson()
                ->get(rtrim($baseUrl, '/').'/'.ltrim($path, '/'));

            $checks = $response->json('checks');
            if (is_array($checks) && $checks !== []) {
                $components = $this->normalizeChecks($checks);

                if (! $response->successful() || $response->json('status') !== $expectedStatus) {
                    $components[] = $this->unavailableComponent();
                }

                return $components;
            }

            $ready = $response->successful() && $response->json('status') === $expectedStatus;

            return [[
                'name' => __('Application readiness'),
                'description' => __('The application readiness endpoint is responding.'),
                'operational' => $ready,
            ]];
        } catch (Throwable $exception) {
            report($exception);

            return [$this->unavailableComponent()];
        }
    }

    /** @param array<array-key, mixed> $checks
     * @return list<array{name: string, description: string, operational: bool}>
     */
    private function normalizeChecks(array $checks): array
    {
        $labels = [
            'database' => [__('Database'), __('The application database is responding.')],
            'cache' => [__('Cache'), __('The application cache is responding.')],
            'background_processing' => [__('Background processing'), __('Application background jobs are being processed.')],
        ];
        $components = [];

        foreach ($labels as $key => [$name, $description]) {
            if (array_key_exists($key, $checks) && is_bool($checks[$key])) {
                $components[] = [
                    'name' => $name,
                    'description' => $description,
                    'operational' => $checks[$key],
                ];
            }
        }

        return $components !== [] ? $components : [$this->unavailableComponent()];
    }

    /** @return array{name: string, description: string, operational: bool} */
    private function unavailableComponent(): array
    {
        return [
            'name' => __('Application readiness'),
            'description' => __('Current service status could not be confirmed.'),
            'operational' => false,
        ];
    }
}
