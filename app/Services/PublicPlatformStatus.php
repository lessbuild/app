<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

class PublicPlatformStatus
{
    private const CACHE_KEY = 'buildpusher:public-platform-status:v1';

    /**
     * Bind internal diagnostics used to produce public platform status.
     *
     * @param  OperationalDiagnostics  $diagnostics  Supplies the operational results summarized for public display.
     */
    public function __construct(private readonly OperationalDiagnostics $diagnostics) {}

    /**
     * @return array{status: string, operational: bool, checked_at: string, components: list<array{name: string, description: string, status: string, operational: bool}>}
     */
    public function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addSeconds(30), function (): array {
            try {
                $checks = collect($this->diagnostics->run())->keyBy('name');
                $groups = [
                    'Website' => [
                        'description' => 'The BuildPusher dashboard and account experience.',
                        'checks' => ['Application key', 'Application URL', 'Database connection', 'Database migrations', 'Storage directory', 'Bootstrap cache'],
                    ],
                    'API' => [
                        'description' => 'Public health and authenticated control-plane APIs.',
                        'checks' => ['Application URL', 'Database connection', 'Database migrations'],
                    ],
                    'Deployments' => [
                        'description' => 'Release orchestration and deployment processing.',
                        'checks' => ['Database connection', 'Queue connection', 'Pending queue state', 'Application services'],
                    ],
                    'Webhooks' => [
                        'description' => 'Repository events and outbound alert delivery.',
                        'checks' => ['Database connection', 'Queue connection', 'Pending queue state', 'Application services'],
                    ],
                    'Background jobs' => [
                        'description' => 'Queues, scheduled health checks, and automation.',
                        'checks' => ['Queue connection', 'Pending queue state', 'Failed queue jobs', 'Application services', 'Automation timers'],
                    ],
                ];

                $components = collect($groups)->map(function (array $group, string $name) use ($checks): array {
                    $operational = collect($group['checks'])->every(
                        fn (string $check): bool => (bool) data_get($checks->get($check), 'passed', false),
                    );

                    return [
                        'name' => $name,
                        'description' => $group['description'],
                        'status' => $operational ? 'Operational' : 'Degraded',
                        'operational' => $operational,
                    ];
                })->values()->all();
            } catch (Throwable $exception) {
                report($exception);
                $components = collect(['Website', 'API', 'Deployments', 'Webhooks', 'Background jobs'])
                    ->map(fn (string $name): array => [
                        'name' => $name,
                        'description' => 'Current service status could not be confirmed.',
                        'status' => 'Degraded',
                        'operational' => false,
                    ])->all();
            }

            $operational = collect($components)->every('operational');

            return [
                'status' => $operational ? 'operational' : 'degraded',
                'operational' => $operational,
                'checked_at' => now()->utc()->toIso8601String(),
                'components' => $components,
            ];
        });
    }
}
