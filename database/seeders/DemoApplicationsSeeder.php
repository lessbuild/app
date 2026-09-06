<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoApplicationsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::query()->where('email', DemoSeeder::EMAIL)->firstOrFail();
            $organization = $user->currentOrganization;
            $websites = Website::query()
                ->where('organization_id', $organization->id)
                ->get()
                ->keyBy('deployment_slug');

            foreach ($this->definitions() as $definition) {
                $project = Project::query()->updateOrCreate(
                    ['organization_id' => $organization->id, 'slug' => $definition['slug']],
                    [
                        'created_by' => $user->id,
                        'name' => DemoSeeder::PREFIX.$definition['name'],
                        'description' => $definition['description'],
                        'preset' => $definition['preset'],
                        'workflow_yaml' => $definition['workflow'] ?? null,
                        'preview_enabled' => $definition['previews'] ?? false,
                        'preview_domain' => ($definition['previews'] ?? false) ? $definition['slug'].'.previews.example.com' : null,
                        'preview_ttl_hours' => 72,
                    ],
                );

                foreach ($definition['environments'] as $environmentDefinition) {
                    $website = isset($environmentDefinition['website'])
                        ? $websites->get($environmentDefinition['website'])
                        : null;
                    $environment = $project->environments()->updateOrCreate(
                        ['slug' => $environmentDefinition['slug']],
                        [
                            'name' => $environmentDefinition['name'],
                            'type' => $environmentDefinition['type'],
                            'branch' => $environmentDefinition['branch'],
                            'server_id' => $website?->server_id,
                            'website_id' => $website?->id,
                            'is_protected' => $environmentDefinition['protected'] ?? false,
                            'requires_deployment_approval' => $environmentDefinition['approval'] ?? false,
                            'minimum_replicas' => $environmentDefinition['minimum'] ?? 1,
                            'maximum_replicas' => $environmentDefinition['maximum'] ?? 1,
                            'desired_replicas' => $environmentDefinition['desired'] ?? 1,
                            'hibernate_after_minutes' => $environmentDefinition['hibernate'] ?? null,
                            'last_activity_at' => now()->subMinutes($environmentDefinition['inactive_minutes'] ?? 2),
                            'hibernated_at' => ($environmentDefinition['hibernated'] ?? false) ? now()->subMinutes(20) : null,
                            'status' => 'ready',
                        ],
                    );

                    foreach ($environmentDefinition['variables'] ?? [] as $key => $value) {
                        $environment->variables()->updateOrCreate(['key' => $key], [
                            'value' => $value,
                            'is_secret' => str_contains($key, 'KEY') || str_contains($key, 'SECRET'),
                            'updated_by' => $user->id,
                        ]);
                    }
                    foreach ($environmentDefinition['processes'] ?? [] as $name => $process) {
                        $environment->processes()->updateOrCreate(['name' => $name], [
                            'type' => $process['type'],
                            'command' => $process['command'],
                            'replicas' => $process['replicas'] ?? 1,
                            'is_enabled' => true,
                        ]);
                    }
                    foreach ($environmentDefinition['resources'] ?? [] as $name => $resource) {
                        $environment->resources()->updateOrCreate(['name' => $name], [
                            'type' => $resource['type'],
                            'is_managed' => $resource['managed'] ?? false,
                            'configuration' => ['variables' => $resource['variables'] ?? []],
                            'status' => $resource['status'] ?? 'ready',
                        ]);
                    }
                }
            }

            $statusPage = StatusPage::query()->updateOrCreate(
                ['slug' => 'demo-status'],
                [
                    'organization_id' => $organization->id,
                    'created_by' => $user->id,
                    'name' => 'BuildPusher Demo Status',
                    'description' => 'Live service health for the demo workspace.',
                    'is_published' => true,
                ],
            );
            $statusPage->websites()->sync(collect([
                [$websites->get('demo-storefront'), 'Storefront'],
                [$websites->get('demo-status'), 'Status API'],
            ])->filter(fn (array $component): bool => $component[0] !== null)
                ->values()
                ->mapWithKeys(fn (array $component, int $position): array => [
                    $component[0]->id => ['display_name' => $component[1], 'position' => $position],
                ])->all());
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function definitions(): array
    {
        return [
            [
                'slug' => 'demo-commerce', 'name' => 'Commerce', 'preset' => 'laravel-inertia', 'previews' => true,
                'description' => 'Customer storefront with protected releases, queues, cache, and pull-request previews.',
                'workflow' => "version: 1\nenvironments:\n  production:\n    deployment:\n      cron: '0 3 * * 1-5'\n      timezone: UTC",
                'environments' => [
                    ['slug' => 'production', 'name' => 'Production', 'type' => 'production', 'branch' => 'main', 'website' => 'demo-storefront', 'protected' => true, 'approval' => true, 'minimum' => 2, 'maximum' => 6, 'desired' => 3, 'variables' => ['APP_REGION' => 'us-east', 'CHECKOUT_SECRET' => 'demo-encrypted-checkout-secret'], 'processes' => ['orders' => ['type' => 'worker', 'command' => 'php artisan queue:work --queue=orders', 'replicas' => 3], 'scheduler' => ['type' => 'scheduler', 'command' => 'php artisan schedule:work']], 'resources' => ['primary-db' => ['type' => 'mysql', 'managed' => true], 'cache' => ['type' => 'redis', 'managed' => true]]],
                    ['slug' => 'staging', 'name' => 'Staging', 'type' => 'staging', 'branch' => 'develop', 'hibernate' => 30, 'hibernated' => true, 'variables' => ['APP_REGION' => 'us-east']],
                ],
            ],
            [
                'slug' => 'demo-status-hub', 'name' => 'Status Hub', 'preset' => 'laravel',
                'description' => 'Public service-health application demonstrating an unhealthy production environment.',
                'environments' => [
                    ['slug' => 'production', 'name' => 'Production', 'type' => 'production', 'branch' => 'production', 'website' => 'demo-status', 'protected' => true, 'approval' => true, 'processes' => ['incidents' => ['type' => 'worker', 'command' => 'php artisan queue:work --queue=incidents']]],
                    ['slug' => 'development', 'name' => 'Development', 'type' => 'development', 'branch' => 'develop', 'hibernate' => 15],
                ],
            ],
            [
                'slug' => 'demo-worker-api', 'name' => 'Worker API', 'preset' => 'laravel-api',
                'description' => 'Background-processing API illustrating a release blocked by failed infrastructure.',
                'environments' => [
                    ['slug' => 'production', 'name' => 'Production', 'type' => 'production', 'branch' => 'release', 'website' => 'demo-provisioning-failure', 'protected' => true, 'maximum' => 4, 'desired' => 2, 'processes' => ['events' => ['type' => 'worker', 'command' => 'php artisan queue:work --queue=events', 'replicas' => 2]], 'resources' => ['events-cache' => ['type' => 'redis', 'status' => 'degraded']]],
                    ['slug' => 'qa', 'name' => 'QA', 'type' => 'staging', 'branch' => 'qa'],
                ],
            ],
            [
                'slug' => 'demo-docs', 'name' => 'Documentation', 'preset' => 'custom',
                'description' => 'Documentation site ready for infrastructure and source-control onboarding.',
                'environments' => [
                    ['slug' => 'production', 'name' => 'Production', 'type' => 'production', 'branch' => 'main', 'protected' => true],
                ],
            ],
            [
                'slug' => 'demo-analytics', 'name' => 'Analytics', 'preset' => 'laravel-api',
                'description' => 'Multi-environment analytics service with scheduled ingestion and external object storage.',
                'environments' => [
                    ['slug' => 'production', 'name' => 'Production', 'type' => 'production', 'branch' => 'main', 'protected' => true, 'approval' => true, 'minimum' => 1, 'maximum' => 8, 'desired' => 2, 'variables' => ['WAREHOUSE_REGION' => 'eu-west'], 'processes' => ['ingestion' => ['type' => 'worker', 'command' => 'php artisan queue:work --queue=ingestion', 'replicas' => 2], 'scheduler' => ['type' => 'scheduler', 'command' => 'php artisan schedule:work']], 'resources' => ['warehouse' => ['type' => 'object_storage', 'variables' => ['STORAGE_BUCKET' => 'demo-analytics']]]],
                    ['slug' => 'preview', 'name' => 'Preview', 'type' => 'preview', 'branch' => 'feature/warehouse', 'hibernate' => 15, 'hibernated' => true],
                ],
            ],
        ];
    }
}
