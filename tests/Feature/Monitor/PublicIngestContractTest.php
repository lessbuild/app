<?php

namespace Tests\Feature\Monitor;

use App\Modules\Monitor\Http\Middleware\AuthenticateIngestToken;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\IngestReceipt;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\Release;
use App\Modules\Monitor\Services\MonitorPublicApiLimits;
use App\Modules\Monitor\Services\OpenApiDocument;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicIngestContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['lessbuild.trusted_hosts' => ['monitor.example.test']]);

        Artisan::call('platform:migrate', ['module' => 'monitor']);

        app('router')->aliasMiddleware('monitor.ingest.token', AuthenticateIngestToken::class);
        RateLimiter::for('monitor.ingest', static fn (Request $request): Limit => Limit::perMinute(240)->by('monitor-ingest-test'));
        RateLimiter::for('monitor.deployments', static fn (Request $request): Limit => Limit::perMinute(60)->by('monitor-deployments-test'));

        Route::domain('monitor.example.test')
            ->middleware('api')
            ->prefix('api')
            ->as('monitor.')
            ->group(app_path('Modules/Monitor/Routes/api.php'));
    }

    public function test_deployment_ingest_is_token_authenticated_and_idempotent(): void
    {
        $token = $this->collector('deployment-secret');
        $payload = [
            'deployment_id' => '588d598a-dc92-4502-9bd3-2710bb1dfd5a',
            'version' => 'release-42',
            'service' => 'api',
            'commit_sha' => 'abcdef1234567',
            'deployed_at' => '2026-09-21T12:00:00Z',
        ];

        $response = $this->postJson('https://monitor.example.test/api/v1/deployments', $payload);
        $response->assertUnauthorized();

        $this->withToken('deployment-secret')
            ->postJson('https://monitor.example.test/api/v1/deployments', $payload)
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.environment_id', $token->environment_id)
            ->assertJsonPath('data.version', 'release-42')
            ->assertJsonPath('data.replayed', false);

        $this->postJson('https://monitor.example.test/api/v1/deployments', $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, Deployment::query()->count());
        $this->assertSame(1, Release::query()->count());
        $this->assertDatabaseEmpty(['telemetry_events', 'ingest_receipts'], 'monitor');
    }

    public function test_telemetry_ingest_deduplicates_event_ids_on_retry(): void
    {
        $this->collector('telemetry-secret');
        $payload = [
            'batch_id' => 'contract-batch-001',
            'events' => [[
                'id' => 'contract-event-001',
                'type' => 'request',
                'name' => 'GET /health',
                'route' => '/health',
                'service' => 'api',
                'status_code' => 200,
                'duration_ms' => 18,
                'trace_id' => 'contract-trace-001',
                'timestamp' => '2026-09-21T12:00:00Z',
            ]],
        ];

        $first = $this->withToken('telemetry-secret')
            ->postJson('https://monitor.example.test/api/v1/ingest', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.batch_id', 'contract-batch-001')
            ->assertJsonPath('data.accepted', 1)
            ->assertJsonPath('data.duplicates', 0)
            ->assertJsonPath('data.status', 'queued');

        $receiptId = $first->json('data.receipt_id');
        $this->assertIsString($receiptId);

        $this->postJson('https://monitor.example.test/api/v1/ingest', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.accepted', 0)
            ->assertJsonPath('data.duplicates', 1)
            ->assertJsonPath('data.receipt_id', $receiptId)
            ->assertJsonPath('data.replayed', true);

        $this->getJson('https://monitor.example.test/api/v1/ingest/receipts/'.$receiptId)
            ->assertOk()
            ->assertJsonPath('data.id', $receiptId)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.accepted', 1)
            ->assertJsonPath('data.attempts', 2);

        $this->assertDatabaseCount('telemetry_events', 0, 'monitor');
        $this->assertDatabaseCount('ingest_receipts', 1, 'monitor');
        $this->assertDatabaseCount('ingest_payloads', 1, 'monitor');
        $receipt = IngestReceipt::query()->findOrFail($receiptId);
        $storedPayload = DB::connection('monitor')->table('ingest_payloads')
            ->where('ingest_receipt_id', $receiptId)
            ->value('payload');
        $this->assertSame('queued', $receipt->status->value);
        $this->assertIsString($storedPayload);
        $this->assertStringNotContainsString('contract-event-001', $storedPayload);
        $this->assertSame('GET /health', $receipt->ingestPayload?->payload[0]['event']['name']);
    }

    public function test_otlp_empty_export_is_successful_without_creating_telemetry(): void
    {
        $token = $this->collector('otlp-secret');

        $this->withToken('otlp-secret')
            ->postJson('https://monitor.example.test/api/v1/otlp/v1/traces', ['resourceSpans' => []])
            ->assertOk()
            ->assertContent('{}')
            ->assertHeader('X-Beacon-Accepted', '0')
            ->assertHeader('X-Beacon-Duplicates', '0');

        $this->assertDatabaseCount('telemetry_events', 0, 'monitor');
        $this->assertSame(0, $token->environment->fresh()->event_count);
        $this->assertNull($token->environment->fresh()->last_seen_at);
    }

    public function test_openapi_paths_responses_and_limits_match_the_public_api_contract(): void
    {
        $document = app(OpenApiDocument::class)->make('https://monitor.example.test');
        $documented = collect($document['paths'])
            ->flatMap(fn (array $pathOperations, string $path) => collect($pathOperations)
                ->keys()
                ->map(fn (string $method): string => strtoupper($method).' '.$path))
            ->sort()
            ->values()
            ->all();
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/')
                && str_starts_with((string) $route->getName(), 'monitor.api.')
                && $route->getName() !== 'monitor.api.openapi')
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => $method.' /'.$route->uri()))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($registered, $documented);
        $this->assertArrayHasKey('201', $document['paths']['/api/v1/deployments']['post']['responses']);
        $this->assertArrayHasKey('200', $document['paths']['/api/v1/deployments']['post']['responses']);
        $this->assertArrayHasKey('429', $document['paths']['/api/v1/ingest']['post']['responses']);
        $this->assertArrayHasKey('400', $document['paths']['/api/v1/ingest']['post']['responses']);
        $this->assertArrayHasKey('413', $document['paths']['/api/v1/ingest']['post']['responses']);
        $this->assertArrayHasKey('415', $document['paths']['/api/v1/queues/{queue}/workers']['post']['responses']);
        $this->assertSame(MonitorPublicApiLimits::INGEST_TOKEN_PER_MINUTE,
            $document['paths']['/api/v1/ingest']['post']['x-rate-limits'][0]['requests']);
        $this->assertSame(MonitorPublicApiLimits::DEPLOYMENT_TOKEN_PER_MINUTE,
            $document['paths']['/api/v1/deployments']['post']['x-rate-limits'][1]['requests']);
        $this->assertSame(MonitorPublicApiLimits::QUEUE_WORKER_MONITOR_PER_MINUTE,
            $document['paths']['/api/v1/queues/{queue}/workers']['post']['x-rate-limits'][1]['requests']);
        $this->assertSame('#/components/schemas/QueueSnapshotRequest',
            $document['paths']['/api/v1/queues/{queue}/snapshots']['post']['requestBody']['content']['application/json']['schema']['$ref']);
    }

    public function test_environment_token_rate_limit_returns_standard_retry_headers(): void
    {
        Cache::flush();
        RateLimiter::for('monitor.ingest', static fn (Request $request): Limit => Limit::perMinute(1)->by('monitor-ingest-rate-test'));
        $this->collector('rate-limit-secret');
        $payload = [
            'batch_id' => 'rate-limit-batch-001',
            'events' => [['id' => 'rate-limit-event-001', 'type' => 'log', 'name' => 'Rate limit contract']],
        ];

        $this->withToken('rate-limit-secret')
            ->postJson('https://monitor.example.test/api/v1/ingest', $payload)
            ->assertAccepted();

        $this->withToken('rate-limit-secret')
            ->postJson('https://monitor.example.test/api/v1/ingest', $payload)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    private function collector(string $secret): IngestToken
    {
        $application = Application::factory()->create();
        $environment = $application->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
        ]);

        return IngestToken::factory()->for($environment)->withSecret($secret)->create();
    }
}
