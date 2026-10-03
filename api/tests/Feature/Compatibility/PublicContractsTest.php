<?php

declare(strict_types=1);

namespace Tests\Feature\Compatibility;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\ApiScope;
use App\Models\Project;
use App\Services\Admin\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

/**
 * The public contracts of the three old applications, in one place. Each keeps its method and path, and its answer to
 * a request without credentials (or with a bad signature) is pinned, so changing one shows up here.
 */
final class PublicContractsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check a contract's route still exists and answers an unauthenticated or unsigned request as before.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  int  $status
     * @param  array<string, mixed>|null  $json  a subset of the JSON answer
     * @return void
     */
    #[DataProvider('contracts')]
    public function test_the_contract_keeps_its_path_and_answer(string $method, string $uri, int $status, ?array $json): void
    {
        $response = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{}');

        $response->assertStatus($status);
        if ($json !== null) {
            $response->assertJson($json);
        }
    }

    /**
     * Get each contract: method, path, the status code without credentials, and part of the JSON answer.
     *
     * @return iterable<string, array{string, string, int, array<string, mixed>|null}>
     */
    public static function contracts(): iterable
    {
        yield 'analytics collect preflight' => ['OPTIONS', '/api/v1/collect/abc123', 403, null];
        yield 'analytics collect' => ['POST', '/api/v1/collect/abc123', 422, null];
        yield 'monitor heartbeat' => ['POST', '/api/v1/heartbeats/999', 401, null];
        yield 'monitor queue snapshots' => ['POST', '/api/v1/queues/999/snapshots', 401, null];
        yield 'monitor queue workers' => ['POST', '/api/v1/queues/999/workers', 401, null];
        yield 'monitor ingest' => ['POST', '/api/v1/ingest', 401, null];
        yield 'monitor ingest receipt' => ['GET', '/api/v1/ingest/receipts/01ARZ3NDEKTSV4RRFFQ69G5FAV', 401, null];
        yield 'monitor otlp' => ['POST', '/api/v1/otlp/v1/traces', 401, null];
        yield 'monitor deployments' => ['POST', '/api/v1/deployments', 401, null];
        yield 'deploy repository webhook' => ['POST', '/api/repositories/999/webhook', 404, ['status' => 'not_found']];
        yield 'deploy github app webhook' => ['POST', '/api/github-app/webhook', 401, null];
        yield 'deploy github app callback' => ['GET', '/github-app/callback', 401, null];
        yield 'deploy build callback' => ['POST', '/builds/1/deployment/callback/status', 403, null];
        yield 'infrastructure server callback' => ['POST', '/servers/1/provisioning/callback/status', 403, null];
        yield 'infrastructure website callback' => ['POST', '/websites/1/provisioning/callback/status', 403, null];
        yield 'deployer api me' => ['GET', '/api/v1/me', 401, null];
        yield 'deployer api projects' => ['GET', '/api/v1/projects', 401, null];
        yield 'deployer api deployments' => ['GET', '/api/v1/deployments', 401, null];
        yield 'deployer api deploy' => ['POST', '/api/v1/environments/1/deploy', 401, null];
        yield 'deployer api runtime' => ['PATCH', '/api/v1/environments/1/runtime', 401, null];
        yield 'deployer api workflow' => ['PUT', '/api/v1/projects/1/workflow', 401, null];
        yield 'deployer api configuration plan' => ['POST', '/api/v1/projects/1/configuration/plan', 401, null];
        yield 'stripe webhook' => ['POST', '/webhooks/stripe', 400, null];
        yield 'status page' => ['GET', '/status/unknown-page', 404, null];
        yield 'status page report' => ['GET', '/status/unknown-page/report.json', 404, null];
        yield 'old status page address' => ['GET', '/status/monitor/unknown-page', 301, null];
    }

    public function test_the_tracker_script_is_served_from_its_old_address(): void
    {
        $this->assertFileExists(public_path('tracker/v1.js'));
        $this->assertStringContainsString('/api/v1/collect/', (string) file_get_contents(public_path('tracker/v1.js')));
    }

    public function test_every_contract_route_is_still_named(): void
    {
        foreach (['analytics.collect', 'api.heartbeats.store', 'api.queues.snapshots.store', 'api.queues.workers.store', 'api.ingest', 'api.ingest.receipts.show', 'api.otlp',
            'webhooks.repositories.receive', 'github-app.webhook', 'webhooks.stripe', 'status.show', 'status.report', 'status.subscribe', 'platform.status', 'platform.status.report',
            'api.v1.me', 'api.v1.projects', 'api.v1.deployments', 'api.v1.environments.deploy', 'api.v1.environments.scale', 'api.v1.environments.runtime', 'api.v1.projects.workflow'] as $name) {
            $this->assertTrue(Route::has($name), "The {$name} route is gone.");
        }
    }

    public function test_the_deployer_api_accepts_old_numeric_ids(): void
    {
        $project = Project::factory()->withServices(['deploy'])->create();
        $project->forceFill(['legacy_id' => 42])->save();
        $project->environments()->where('slug', 'production')->update(['legacy_id' => 7]);
        $owner = $this->ownerOf($project);
        $token = app(CreateApiToken::class)->handle($owner, $project->account, new CreateApiTokenData('ci', [ApiScope::DeployRead, ApiScope::DeployWrite], 30))->plainText;

        $this->withToken($token)->getJson('/api/v1/projects/42')->assertOk()->assertJsonPath('data.id', $project->id);
        $this->withToken($token)->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->withToken($token)->getJson('/api/v1/projects/43')->assertNotFound();
        $this->withToken($token)->patchJson('/api/v1/environments/7/runtime', ['state' => 'running'])->assertStatus(202);
    }

    public function test_the_platform_status_page_and_report_keep_cores_shape(): void
    {
        Cache::forever(SystemHealth::HEARTBEAT_KEY, now()->getTimestamp());

        $report = $this->getJson('/status/report.json')->assertOk()->assertHeader('Cache-Control', 'max-age=30, public');
        $report->assertJsonStructure(['status', 'operational', 'checked_at', 'components' => [['name', 'description', 'status', 'operational']]]);
        $this->assertSame(['operational', true], [$report->json('status'), $report->json('operational')]);
        $this->assertContains('Deploy', $report->collect('components')->pluck('name')->all());
        $this->get('/status')->assertOk()->assertSee('All systems operational')->assertSee('Background processing');

        Cache::forget(SystemHealth::HEARTBEAT_KEY);
        Cache::forget('platform:status');
        $this->assertSame('degraded', $this->getJson('/status/report.json')->json('status'));

        config(['platform.status_page' => 'our-status']);
        $this->get('/status')->assertRedirect('/status/our-status');
    }
}
