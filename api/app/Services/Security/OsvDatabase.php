<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\Security\VulnerabilityDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The open OSV database (osv.dev): a batch query finds which package versions have advisories, then each advisory's
 * details (summary, severity, fixed version) are read once and cached for a day.
 */
final class OsvDatabase implements VulnerabilityDatabase
{
    /**
     * Packages per batch query.
     *
     * @var int
     */
    private const BATCH = 500;

    /**
     * Find the known vulnerabilities affecting each package version.
     *
     * @param  list<array{ecosystem: string, name: string, version: string}>  $packages
     * @return array<int, list<array{id: string, severity: string, summary: string, fixed: string|null, url: string}>>
     */
    public function lookup(array $packages): array
    {
        $results = [];
        foreach (array_chunk($packages, self::BATCH, true) as $chunk) {
            $queries = [];
            foreach ($chunk as $package) {
                $queries[] = ['package' => ['name' => $package['name'], 'ecosystem' => $package['ecosystem']], 'version' => $package['version']];
            }
            $response = Http::timeout(30)->acceptJson()->post('https://api.osv.dev/v1/querybatch', ['queries' => $queries])->throw();
            $answers = (array) $response->json('results', []);
            $index = 0;
            foreach ($chunk as $key => $package) {
                $ids = array_column((array) (($answers[$index++] ?? [])['vulns'] ?? []), 'id');
                $results[$key] = array_values(array_filter(array_map(fn (mixed $id): ?array => is_string($id) ? $this->details($id, $package['name']) : null, $ids)));
            }
        }

        return $results;
    }

    /**
     * Read one advisory's summary, severity and the version that fixes it for the package.
     *
     * @param  string  $id
     * @param  string  $package
     * @return array{id: string, severity: string, summary: string, fixed: string|null, url: string}|null
     */
    private function details(string $id, string $package): ?array
    {
        $advisory = Cache::remember('osv.vuln.'.$id, 86400, function () use ($id): array {
            $response = Http::timeout(15)->acceptJson()->get('https://api.osv.dev/v1/vulns/'.rawurlencode($id));

            return $response->successful() ? (array) $response->json() : [];
        });
        if ($advisory === [] || isset($advisory['withdrawn'])) {
            return null;
        }
        $fixed = null;
        foreach ((array) ($advisory['affected'] ?? []) as $affected) {
            if (! is_array($affected) || strcasecmp((string) ($affected['package']['name'] ?? ''), $package) !== 0) {
                continue;
            }
            foreach ((array) ($affected['ranges'] ?? []) as $range) {
                foreach ((array) (is_array($range) ? ($range['events'] ?? []) : []) as $event) {
                    if (is_array($event) && is_string($event['fixed'] ?? null)) {
                        $fixed = $event['fixed'];
                    }
                }
            }
        }
        $label = strtolower((string) ($advisory['database_specific']['severity'] ?? ''));

        return [
            'id' => $id,
            'severity' => match ($label) {
                'critical' => 'critical',
                'high' => 'high',
                'low' => 'low',
                default => 'medium',
            },
            'summary' => mb_substr((string) ($advisory['summary'] ?? $advisory['details'] ?? $id), 0, 300),
            'fixed' => $fixed,
            'url' => 'https://osv.dev/vulnerability/'.rawurlencode($id),
        ];
    }
}
