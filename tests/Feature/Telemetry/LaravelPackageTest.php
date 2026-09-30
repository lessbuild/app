<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Support\Telemetry\IntegrationSetupGuide;
use BuildPusher\Laravel\BuildPusherServiceProvider;
use BuildPusher\Laravel\Recorder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

final class LaravelPackageTest extends TestCase
{
    /**
     * Check the Laravel package (sdk/laravel) records a failing request with its exception, a slow query and a log line
     * under one trace, sends them in one batch after the response, records queue jobs, and marks deploys.
     *
     * @return void
     */
    public function test_the_laravel_package_sends_a_request_its_exception_and_queries(): void
    {
        Http::fake(['bp.test/*' => Http::response(['data' => []], 200)]);
        config(['buildpusher' => ['token' => 'ingest-key', 'endpoint' => 'https://bp.test/api/v1', 'service' => 'shop', 'release' => 'v1.4.0', 'request_sample_rate' => 1.0, 'slow_query_ms' => 0, 'log_level' => 'warning', 'ignore_paths' => ['up']]]);
        $this->app->register(BuildPusherServiceProvider::class);
        Route::get('/package-test/{order}', function () {
            DB::select('select 1');
            Log::warning('Stock is low');
            throw new RuntimeException('Payment gateway timed out');
        });

        $this->get('/package-test/42')->assertServerError();

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://bp.test/api/v1/ingest') {
                return false;
            }
            /** @var list<array<string, mixed>> $sent */
            $sent = $request['events'];
            $events = collect($sent)->keyBy('type')->all();
            $this->assertSame('Bearer ingest-key', $request->header('Authorization')[0]);
            $this->assertSame(['request', 'exception', 'query', 'log'], array_values(array_intersect(['request', 'exception', 'query', 'log'], array_keys($events))));
            $this->assertSame(['/package-test/{order}', 500, 'error'], [$events['request']['route'], $events['request']['status_code'], $events['request']['severity']]);
            $this->assertSame(['Payment gateway timed out', RuntimeException::class, '/package-test/{order}'], [$events['exception']['title'], $events['exception']['name'], $events['exception']['route']]);
            $this->assertSame('select 1', $events['query']['name']);
            $this->assertSame('Stock is low', $events['log']['name']);
            $this->assertCount(1, collect($sent)->pluck('trace_id')->unique(), 'One trace for the request.');
            $this->assertSame(['shop', 'v1.4.0'], [$events['request']['service'], $events['request']['attributes']['service.version']]);

            return true;
        });
        $this->assertSame([], app(Recorder::class)->pending());

        dispatch(function (): void {});
        Http::assertSent(fn (Request $request): bool => in_array(['type' => 'job', 'severity' => 'info'], array_map(fn (array $event): array => ['type' => $event['type'], 'severity' => $event['severity']], (array) ($request['events'] ?? [])), true));

        $deploy = $this->artisan('buildpusher:deploy', ['version' => 'v1.4.1', '--commit' => 'abcdef1']);
        $this->assertInstanceOf(PendingCommand::class, $deploy);
        $deploy->assertSuccessful()->run();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://bp.test/api/v1/deployments' && $request['version'] === 'v1.4.1' && $request['commit_sha'] === 'abcdef1' && $request['service'] === 'shop');

        $guide = app(IntegrationSetupGuide::class)->for('laravel', 'https://bp.test/api/v1/ingest', 'https://bp.test/api/v1/ingest/receipts/RECEIPT_ID');
        $this->assertStringContainsString('composer require buildpusher/laravel', $guide['code']);
        $this->assertStringContainsString('BUILDPUSHER_ENDPOINT=https://bp.test/api/v1', $guide['code']);
    }
}
