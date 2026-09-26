<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\DeploymentObservation;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\OperationalIncident;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Models\WebsiteHealthCheck;
use App\Modules\Deployer\Models\WebsiteLogSnapshot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservabilityEnvironmentContextTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_member_can_view_bounded_environment_evidence_without_sensitive_bodies(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$owner, $environment, $website, $server, $repository] = $this->environment();
        $recentBuild = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_FAILED,
            'revision' => str_repeat('a', 40),
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'created_at' => now()->subHours(2),
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHour(),
        ]);
        $recentBuild->logs()->create([
            'type' => Build::DEPLOYMENT_LOG_TYPE,
            'log' => 'private-deployment-output',
        ]);
        $oldTerminalBuild = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('b', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subDays(2),
        ]);
        $oldActiveBuild = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_RUNNING,
            'revision' => str_repeat('c', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subDays(2),
        ]);
        $website->healthChecks()->create([
            'successful' => false,
            'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
            'http_status' => 503,
            'duration_ms' => 120,
            'endpoint' => 'https://app.example.com/health',
            'error' => 'private-health-error',
            'checked_at' => now()->subHours(3),
        ]);
        $website->healthChecks()->create([
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'http_status' => 200,
            'duration_ms' => 80,
            'endpoint' => 'https://app.example.com/health',
            'checked_at' => now()->subDays(2),
        ]);
        $website->runtimeLogs()->create([
            'type' => 'application',
            'status' => WebsiteLogSnapshot::STATUS_READY,
            'log' => 'private-runtime-output',
            'refreshed_at' => now()->subHour(),
        ]);
        OperationalIncident::query()->create([
            'organization_id' => $owner->current_organization_id,
            'category' => 'deployment',
            'resource_id' => $recentBuild->id,
            'active_key' => 'deployment:'.$recentBuild->id,
            'status' => OperationalIncident::STATUS_OPEN,
            'severity' => 'major',
            'title' => 'Deployment requires investigation',
            'summary' => 'private-incident-summary',
            'occurrences' => 1,
            'detected_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);
        OperationalIncident::query()->create([
            'organization_id' => $owner->current_organization_id,
            'category' => 'website',
            'resource_id' => $website->id,
            'active_key' => 'website:'.$website->id,
            'status' => OperationalIncident::STATUS_RESOLVED,
            'severity' => 'minor',
            'title' => 'Recent website recovery',
            'summary' => 'private-website-summary',
            'occurrences' => 2,
            'detected_at' => now()->subHours(5),
            'last_seen_at' => now()->subHours(4),
            'resolved_at' => now()->subHours(3),
        ]);

        $response = $this->actingAs($owner)->get(route('observability.environments.context', [
            'environment' => $environment,
            'window' => '24h',
        ]));
        $shareUrl = route('observability.environments.context', [
            'environment' => $environment,
            'window' => '24h',
            'service' => 'all',
            'deployment' => 'all',
            'severity' => 'all',
        ]);

        $response->assertSuccessful()
            ->assertSee('Environment evidence')
            ->assertSee('data-testid="share-environment-context"', false)
            ->assertSee($shareUrl)
            ->assertSee('Deployment requires investigation')
            ->assertSee('Recent website recovery')
            ->assertSee('Open deployment evidence')
            ->assertSee('data-testid="incident-deployment-evidence-link"', false)
            ->assertSee('data-modal-trigger="environment-health-checks-dialog"', false)
            ->assertSee(route('websites.health-checks.index', [
                'website' => $website,
                'fragment' => 'website-health-checks',
            ]), false)
            ->assertSee($recentBuild->shortRevision())
            ->assertSee(route('builds.show', $recentBuild), false)
            ->assertSee(route('websites.runtime-logs.show', [$website, 'application']), false)
            ->assertDontSee('private-deployment-output')
            ->assertDontSee('private-runtime-output')
            ->assertDontSee('private-health-error')
            ->assertDontSee('private-incident-summary')
            ->assertDontSee('private-website-summary');

        $this->assertDoesNotMatchRegularExpression('/<details id="environment-context-filters"[^>]*\bopen\b[^>]*>/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/<details id="save-investigation-view"[^>]*\bopen\b[^>]*>/', $response->getContent());

        $this->assertStringContainsString(
            '<a href="'.route('builds.show', $recentBuild).'" class="ui-link text-xs" data-testid="incident-deployment-evidence-link">Open deployment evidence</a>',
            $response->getContent(),
        );

        $response->assertViewHas('context', function ($context) use ($oldTerminalBuild, $oldActiveBuild): bool {
            return $context->builds->pluck('id')->contains($oldActiveBuild->id)
                && ! $context->builds->pluck('id')->contains($oldTerminalBuild->id)
                && $context->healthChecks->count() === 1
                && $context->runtimeLogs->first()->getAttribute('log') === null
                && ! array_key_exists('summary', $context->incidents->first()->getAttributes());
        });

        $this->assertSame(3, $environment->builds()->count());
        $this->assertSame(2, $website->healthChecks()->count());
        $this->assertSame(1, $website->runtimeLogs()->count());
        $this->assertSame($server->id, $environment->server_id);

        $this->actingAs($owner)
            ->get(route('observability.index'))
            ->assertSuccessful()
            ->assertSee(route('observability.environments.context', $environment), false);
    }

    public function test_health_history_dialog_preserves_the_environment_context_url(): void
    {
        [$owner, $environment, $website] = $this->environment();

        $response = $this->actingAs($owner)->get(route('observability.environments.context', [
            'environment' => $environment,
            'window' => '7d',
            'dialog' => 'environment-health-checks-dialog',
        ]));

        $response
            ->assertSuccessful()
            ->assertSee('data-modal-initial-open="true"', false)
            ->assertSee('data-modal-trigger="environment-health-checks-dialog"', false)
            ->assertSee(route('websites.health-checks.index', [
                'website' => $website,
                'fragment' => 'website-health-checks',
            ]), false);
    }

    public function test_environment_context_uses_compact_signal_sections_and_filter_controls(): void
    {
        [$owner, $environment] = $this->environment();

        $content = $this->actingAs($owner)
            ->get(route('observability.environments.context', $environment))
            ->assertSuccessful()
            ->assertSee('Environment evidence sections')
            ->assertSee('data-observability-context-card', false)
            ->assertSee('data-observability-context-section', false)
            ->assertSee('id="context-deployments"', false)
            ->assertSee('id="context-health"', false)
            ->assertSee('id="context-logs"', false)
            ->assertSee('id="context-incidents"', false)
            ->assertSee('class="ui-input"', false)
            ->getContent();

        $this->assertStringContainsString('class="ui-panel mt-8 p-5 sm:p-6"', $content);
        $this->assertStringContainsString('class="ui-eyebrow"', $content);
        $source = file_get_contents(resource_path('views/observability/environment-context.blade.php'));
        $this->assertStringContainsString('ui-status-dot', $source);
        $this->assertStringNotContainsString('focus-visible:ring-2 focus-visible:ring-primary', $source);
        $this->assertStringNotContainsString('bg-red-500', $source);
        $this->assertStringNotContainsString('bg-green-500', $source);
    }

    public function test_context_read_is_tenant_authorized_before_window_validation(): void
    {
        [$owner, $environment] = $this->environment();
        $foreign = User::factory()->create();
        $foreignEnvironment = $foreign->currentOrganization->projects()->create([
            'created_by' => $foreign->id,
            'name' => 'Foreign application',
            'slug' => 'foreign-application',
        ])->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
        ]);
        $intruder = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('observability.environments.context', [
                'environment' => $foreignEnvironment,
                'window' => 'unsupported',
            ]))
            ->assertForbidden();
        $this->actingAs($intruder)
            ->get(route('observability.environments.context', [
                'environment' => $environment,
                'window' => 'unsupported',
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('builds', 0);
        $this->assertDatabaseCount('website_health_checks', 0);
    }

    public function test_context_window_is_finite_and_each_evidence_collection_is_bounded(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$owner, $environment, $website, , $repository] = $this->environment();

        foreach (range(1, 25) as $number) {
            $build = $repository->builds()->create([
                'environment_id' => $environment->id,
                'status' => Build::STATUS_SUCCEEDED,
                'revision' => str_repeat((string) ($number % 10), 40),
                'trigger_source' => Build::TRIGGER_MANUAL,
                'created_at' => now()->subMinutes($number),
            ]);
            $this->observation($build, $website);
            $website->healthChecks()->create([
                'successful' => true,
                'source' => WebsiteHealthCheck::SOURCE_AUTOMATIC,
                'http_status' => 200,
                'duration_ms' => 50,
                'endpoint' => 'https://app.example.com/health',
                'checked_at' => now()->subMinutes($number),
            ]);
        }

        $this->actingAs($owner)
            ->get(route('observability.environments.context', [
                'environment' => $environment,
                'window' => 'unsupported',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('window');

        $this->actingAs($owner)
            ->get(route('observability.environments.context', [
                'environment' => $environment,
                'window' => '7d',
            ]))
            ->assertSuccessful()
            ->assertViewHas('context', fn ($context): bool => $context->builds->count() === 20
                && $context->deploymentObservations->count() === 20
                && $context->healthChecks->count() === 20);
    }

    public function test_context_includes_active_revision_bound_observations_without_exposing_remote_details(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$owner, $environment, $website, , $repository] = $this->environment();
        $recentBuild = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('a', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subHour(),
        ]);
        $oldBuild = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('b', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subDays(2),
        ]);
        $this->observation($recentBuild, $website, [
            'status' => DeploymentObservation::STATUS_HEALTHY,
            'successful_checks' => 4,
            'last_http_status' => 200,
            'last_error' => 'remote-observation-error',
            'claim_token' => 'observation-claim-secret',
            'website_url' => 'https://secret-observation.example.test',
            'health_check_path' => '/private-health-path',
        ]);
        $this->observation($oldBuild, $website, [
            'status' => DeploymentObservation::STATUS_OBSERVING,
            'successful_checks' => 1,
            'deadline_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($owner)->get(route('observability.environments.context', [
            'environment' => $environment,
            'window' => '24h',
        ]));

        $response->assertSuccessful()
            ->assertSee('Post-deployment verification')
            ->assertSee('Healthy')
            ->assertSee('Observing')
            ->assertSee('HTTP 200')
            ->assertSee('data-testid="deployment-observation-evidence-'.$recentBuild->id.'"', false)
            ->assertSee('data-testid="deployment-observation-evidence-'.$oldBuild->id.'"', false)
            ->assertDontSee('remote-observation-error')
            ->assertDontSee('observation-claim-secret')
            ->assertDontSee('secret-observation.example.test')
            ->assertDontSee('/private-health-path');

        $response->assertViewHas('context', function ($context) use ($recentBuild, $oldBuild): bool {
            $recent = $context->deploymentObservations->get($recentBuild->id);
            $old = $context->deploymentObservations->get($oldBuild->id);

            return $recent?->status === DeploymentObservation::STATUS_HEALTHY
                && $recent?->successfulChecks === 4
                && $old?->status === DeploymentObservation::STATUS_OBSERVING
                && $context->builds->pluck('id')->contains($oldBuild->id);
        });

        $loadedObservation = $response->viewData('context')->builds->firstWhere('id', $recentBuild->id)->deploymentObservation;
        $this->assertArrayNotHasKey('last_error', $loadedObservation->getAttributes());
        $this->assertArrayNotHasKey('claim_token', $loadedObservation->getAttributes());
        $this->assertArrayNotHasKey('website_url', $loadedObservation->getAttributes());
    }

    public function test_context_service_filter_keeps_observation_outcomes_bound_to_selected_deployment_service(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$owner, $environment, $website, , $repository] = $this->environment();
        $otherRepository = $owner->repositories()->create([
            'provider_id' => $repository->provider_id,
            'website_id' => $website->id,
            'name' => 'Worker service',
            'url' => 'github.com/example/worker.git',
            'branch' => 'main',
            'description' => 'Worker source',
        ]);
        $selectedBuild = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('c', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subMinutes(20),
        ]);
        $otherBuild = $otherRepository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('d', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subMinutes(10),
        ]);
        $this->observation($selectedBuild, $website, ['successful_checks' => 3]);
        $this->observation($otherBuild, $website, ['successful_checks' => 9]);

        $response = $this->actingAs($owner)->get(route('observability.environments.context', [
            'environment' => $environment,
            'service' => $repository->id,
        ]));

        $response->assertSuccessful()
            ->assertSee('data-testid="deployment-observation-evidence-'.$selectedBuild->id.'"', false)
            ->assertDontSee('data-testid="deployment-observation-evidence-'.$otherBuild->id.'"', false);

        $response->assertViewHas('context', fn ($context): bool => $context->serviceId === $repository->id
            && $context->deploymentObservations->keys()->all() === [$selectedBuild->id]);
    }

    public function test_context_does_not_present_an_observation_when_revision_or_website_identity_changed(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$owner, $environment, $website, , $repository] = $this->environment();
        $build = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('e', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subMinutes(10),
        ]);
        $otherWebsite = $owner->websites()->create([
            'server_id' => $website->server_id,
            'name' => 'Other website',
            'description' => 'Other application',
            'environment' => 'APP_SECRET=other-secret',
            'url' => 'other.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $websiteMismatchBuild = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('g', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subMinutes(5),
        ]);
        $this->observation($build, $website, [
            'revision' => str_repeat('f', 40),
            'status' => DeploymentObservation::STATUS_HEALTHY,
        ]);
        $this->observation($websiteMismatchBuild, $otherWebsite);

        $response = $this->actingAs($owner)->get(route('observability.environments.context', [
            'environment' => $environment,
        ]));

        $response->assertSuccessful()
            ->assertDontSee('Post-deployment verification')
            ->assertViewHas('context', fn ($context): bool => $context->deploymentObservations->isEmpty());
    }

    public function test_context_filters_deployments_by_service_and_incidents_by_severity(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$owner, $environment, $website, , $repository] = $this->environment();
        $otherRepository = $owner->repositories()->create([
            'provider_id' => $repository->provider_id,
            'website_id' => $website->id,
            'name' => 'Worker service',
            'url' => 'github.com/example/worker.git',
            'branch' => 'main',
            'description' => 'Worker source',
        ]);
        $selectedBuild = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_FAILED,
            'revision' => str_repeat('d', 40),
            'trigger_source' => Build::TRIGGER_WEBHOOK,
            'created_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
        ]);
        $otherBuild = $otherRepository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('e', 40),
            'trigger_source' => Build::TRIGGER_MANUAL,
            'created_at' => now()->subMinutes(45),
            'finished_at' => now()->subMinutes(15),
        ]);
        OperationalIncident::query()->create([
            'organization_id' => $owner->current_organization_id,
            'category' => 'deployment',
            'resource_id' => $selectedBuild->id,
            'active_key' => 'deployment:'.$selectedBuild->id,
            'status' => OperationalIncident::STATUS_OPEN,
            'severity' => 'critical',
            'title' => 'Critical selected deployment',
            'summary' => 'private critical summary',
            'occurrences' => 1,
            'detected_at' => now()->subMinutes(30),
            'last_seen_at' => now()->subMinutes(30),
        ]);
        OperationalIncident::query()->create([
            'organization_id' => $owner->current_organization_id,
            'category' => 'deployment',
            'resource_id' => $otherBuild->id,
            'active_key' => 'deployment:'.$otherBuild->id,
            'status' => OperationalIncident::STATUS_OPEN,
            'severity' => 'minor',
            'title' => 'Minor other-service deployment',
            'summary' => 'private minor summary',
            'occurrences' => 1,
            'detected_at' => now()->subMinutes(20),
            'last_seen_at' => now()->subMinutes(20),
        ]);

        $response = $this->actingAs($owner)->get(route('observability.environments.context', [
            'environment' => $environment,
            'service' => $repository->id,
            'deployment' => 'unsuccessful',
            'severity' => 'critical',
            'unused' => 'unvalidated-value',
        ]));
        $shareUrl = route('observability.environments.context', [
            'environment' => $environment,
            'window' => '24h',
            'service' => $repository->id,
            'deployment' => 'unsuccessful',
            'severity' => 'critical',
        ]);

        $response->assertSuccessful()
            ->assertSee($shareUrl)
            ->assertSee('Critical selected deployment')
            ->assertSee('Application repository')
            ->assertSee('Worker service')
            ->assertDontSee('Minor other-service deployment')
            ->assertDontSee('unvalidated-value');

        $this->assertMatchesRegularExpression('/<details id="environment-context-filters"[^>]*\bopen\b[^>]*>/', $response->getContent());

        $response->assertViewHas('context', function ($context) use ($repository, $selectedBuild): bool {
            return $context->serviceId === $repository->id
                && $context->deployment === 'unsuccessful'
                && $context->severity === 'critical'
                && $context->services->count() === 2
                && $context->builds->pluck('id')->all() === [$selectedBuild->id]
                && $context->incidents->pluck('severity')->all() === ['critical'];
        });
    }

    /** @return array{User, Environment, Website, Server, Repository} */
    private function environment(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'Source provider',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-token',
            'description' => 'Source control',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Application server',
            'public_ip' => '203.0.113.10',
            'ssh_private_key' => 'private-key',
            'provisioning_status' => 'active',
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application website',
            'description' => 'Application',
            'environment' => 'APP_SECRET=private-environment-value',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Application',
            'slug' => 'application',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
            'server_id' => $server->id,
            'website_id' => $website->id,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$owner, $environment, $website, $server, $repository];
    }

    /** @param array<string, mixed> $attributes */
    private function observation(Build $build, Website $website, array $attributes = []): DeploymentObservation
    {
        return DeploymentObservation::query()->create(array_merge([
            'build_id' => $build->id,
            'website_id' => $website->id,
            'server_id' => $website->server_id,
            'revision' => $build->revision,
            'website_url' => 'app.example.com',
            'health_check_path' => '/health',
            'duration_minutes' => 15,
            'status' => DeploymentObservation::STATUS_HEALTHY,
            'successful_checks' => 2,
            'last_http_status' => 200,
            'last_duration_ms' => 80,
            'started_at' => now()->subMinutes(15),
            'last_checked_at' => now()->subMinute(),
            'deadline_at' => now()->addMinutes(15),
            'completed_at' => now(),
        ], $attributes));
    }
}
