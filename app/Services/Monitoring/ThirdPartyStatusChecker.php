<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\ThirdPartyService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads the public status of the services projects depend on, from each status page's /api/v2/summary.json, once per
 * address however many projects follow it.
 */
final class ThirdPartyStatusChecker
{
    /**
     * The indicators a summary can report.
     *
     * @var list<string>
     */
    private const INDICATORS = ['none', 'minor', 'major', 'critical', 'maintenance'];

    /**
     * Create a new ThirdPartyStatusChecker instance.
     *
     * @param  PublicHttpTarget  $targets  Makes sure addresses are public.
     */
    public function __construct(private readonly PublicHttpTarget $targets) {}

    /**
     * Check every followed status page and update each project's copy. Returns how many addresses were checked.
     *
     * @return int
     */
    public function checkAll(): int
    {
        $urls = ThirdPartyService::query()->distinct()->pluck('url');
        foreach ($urls as $url) {
            $this->check((string) $url);
        }

        return $urls->count();
    }

    /**
     * Check one status page and record the result on every project that follows it.
     *
     * @param  string  $url
     * @return void
     */
    public function check(string $url): void
    {
        $status = $this->fetch($url);
        ThirdPartyService::query()->where('url', $url)->each(function (ThirdPartyService $service) use ($status): void {
            if ($status['error'] !== null) {
                $service->forceFill(['checked_at' => now(), 'last_error' => $status['error']])->save();

                return;
            }
            $service->forceFill([
                'indicator' => $status['indicator'], 'description' => $status['description'], 'affected' => $status['affected'],
                'incident' => $status['incident'], 'checked_at' => now(), 'last_error' => null,
                'changed_at' => $service->indicator !== $status['indicator'] ? now() : $service->changed_at,
            ])->save();
        });
    }

    /**
     * Fetch and read a status page's summary.
     *
     * @param  string  $url
     * @return array{indicator: string, description: string|null, affected: list<string>, incident: string|null, error: string|null}
     */
    private function fetch(string $url): array
    {
        $failed = fn (string $error): array => ['indicator' => 'unknown', 'description' => null, 'affected' => [], 'incident' => null, 'error' => $error];
        $target = $this->targets->resolve($url);
        if ($target['error'] !== null) {
            return $failed(__('The status page’s address isn’t reachable from the internet.'));
        }
        try {
            $response = Http::timeout(10)->withoutRedirecting()->acceptJson()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['address']}"]]])
                ->get(rtrim($url, '/').'/api/v2/summary.json');
        } catch (Throwable) {
            return $failed(__('The status page didn’t answer.'));
        }
        $summary = $response->successful() ? $response->json() : null;
        $indicator = is_array($summary) ? ($summary['status']['indicator'] ?? null) : null;
        if (! is_string($indicator) || ! in_array($indicator, self::INDICATORS, true)) {
            return $failed(__('The status page didn’t return a status summary (HTTP :status).', ['status' => $response->status()]));
        }
        $affected = [];
        foreach ((array) ($summary['components'] ?? []) as $component) {
            if (is_array($component) && ($component['status'] ?? 'operational') !== 'operational' && is_string($component['name'] ?? null) && ! ($component['group'] ?? false)) {
                $affected[] = mb_substr($component['name'], 0, 80);
            }
        }
        $incident = null;
        foreach ((array) ($summary['incidents'] ?? []) as $open) {
            if (is_array($open) && is_string($open['name'] ?? null)) {
                $incident = mb_substr($open['name'], 0, 255);
                break;
            }
        }
        $maintenance = collect((array) ($summary['scheduled_maintenances'] ?? []))->contains(fn (mixed $window): bool => is_array($window) && ($window['status'] ?? null) === 'in_progress');

        return [
            'indicator' => $indicator === 'none' && $maintenance ? 'maintenance' : $indicator,
            'description' => is_string($summary['status']['description'] ?? null) ? mb_substr($summary['status']['description'], 0, 255) : null,
            'affected' => array_slice($affected, 0, 10),
            'incident' => $incident,
            'error' => null,
        ];
    }
}
