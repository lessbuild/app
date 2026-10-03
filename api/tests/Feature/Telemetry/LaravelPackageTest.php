<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Contracts\Telemetry\TelemetryIngestor;
use App\Data\Telemetry\IngestContext;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Telemetry\IntegrationSetupGuide;
use BuildPusher\Laravel\BuildPusherServiceProvider;
use BuildPusher\Laravel\Recorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

final class LaravelPackageTest extends TestCase
{
    use RefreshDatabase;
    use WithConsoleEvents;

    /**
     * Check the Laravel package (sdk/laravel) records a failing request with its timeline (queries and mail), counters,
     * an N+1 query, the exception with the hashed user, and a log line under one trace, sends them in one batch after
     * the response, records queue jobs and commands, and marks deploys.
     *
     * @return void
     */
    public function test_the_laravel_package_records_requests_with_their_timeline(): void
    {
        Http::fake(['bp.test/*' => Http::response(['data' => []], 200)]);
        config(['buildpusher' => ['token' => 'ingest-key', 'endpoint' => 'https://bp.test/api/v1', 'service' => 'shop', 'release' => 'v1.4.0', 'request_sample_rate' => 1.0, 'slow_query_ms' => 5000, 'log_level' => 'warning', 'ignore_paths' => ['up']]]);
        $this->app->register(BuildPusherServiceProvider::class);
        Route::get('/package-test/{order}', function () {
            foreach (range(1, Recorder::N_PLUS_ONE) as $item) {
                DB::select('select ? as item', [$item]);
            }
            Cache::get('missing-key');
            Mail::raw('Your order', fn ($message) => $message->to('a@example.com')->subject('Order received'));
            Log::warning('Stock is low');
            throw new RuntimeException('Payment gateway timed out');
        });
        $user = User::factory()->create();

        $this->actingAs($user)->get('/package-test/42')->assertServerError();

        Http::assertSent(function (Request $request) use ($user): bool {
            if ($request->url() !== 'https://bp.test/api/v1/ingest') {
                return false;
            }
            /** @var list<array<string, mixed>> $sent */
            $sent = $request['events'];
            $events = collect($sent);
            $request = $events->firstWhere('type', 'request');
            $exception = $events->firstWhere('type', 'exception');
            $this->assertSame(['/package-test/{order}', 500], [$request['route'] ?? null, $request['status_code'] ?? null]);
            $this->assertGreaterThanOrEqual(Recorder::N_PLUS_ONE, $request['attributes']['db.query_count'] ?? 0);
            $this->assertSame([1, 1], [$request['attributes']['cache.misses'] ?? null, $request['attributes']['mail.sent'] ?? null]);
            $this->assertSame('Payment gateway timed out', $exception['title'] ?? null);
            $this->assertSame(substr(hash_hmac('sha256', (string) $user->id, (string) config('app.key')), 0, 32), $exception['attributes']['user.id'] ?? null, 'The user is hashed with the app key.');
            $this->assertTrue($events->contains(fn (array $event): bool => str_starts_with((string) $event['name'], 'N+1: select ? as item')), 'Five of the same query is N+1.');
            $this->assertSame(Recorder::N_PLUS_ONE, $events->where('name', 'select ? as item')->count(), 'Each query is on the timeline.');
            $this->assertTrue($events->contains('name', 'Mail: Order received'));
            $this->assertTrue($events->contains('name', 'Stock is low'));
            $this->assertCount(1, $events->pluck('trace_id')->unique(), 'One trace for the request.');

            return true;
        });
        $this->assertSame([], app(Recorder::class)->pending());

        dispatch(function (): void {});
        Http::assertSent(fn (Request $request): bool => in_array(['job', 'info'], array_map(fn (array $event): array => [$event['type'], $event['severity']], (array) ($request['events'] ?? [])), true));

        $about = $this->artisan('env');
        $this->assertInstanceOf(PendingCommand::class, $about);
        $about->run();
        Http::assertSent(fn (Request $request): bool => collect((array) ($request['events'] ?? []))->contains('name', 'Command: env'));

        $deploy = $this->artisan('buildpusher:deploy', ['version' => 'v1.4.1', '--commit' => 'abcdef1']);
        $this->assertInstanceOf(PendingCommand::class, $deploy);
        $deploy->assertSuccessful()->run();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://bp.test/api/v1/deployments' && $request['version'] === 'v1.4.1' && $request['commit_sha'] === 'abcdef1' && $request['service'] === 'shop');

        $guide = app(IntegrationSetupGuide::class)->for('laravel', 'https://bp.test/api/v1/ingest', 'https://bp.test/api/v1/ingest/receipts/RECEIPT_ID');
        $this->assertStringContainsString('composer require buildpusher/laravel', $guide['code']);
        $this->assertStringContainsString('BUILDPUSHER_ENDPOINT=https://bp.test/api/v1', $guide['code']);
    }

    /**
     * Check an issue counts each (hashed) user once across its occurrences.
     *
     * @return void
     */
    public function test_issues_count_each_affected_user_once(): void
    {
        $environment = Project::factory()->withServices(['monitoring'])->create()->environments()->firstOrFail();
        $exception = fn (string $user): array => ['id' => (string) str()->uuid(), 'type' => 'exception', 'title' => 'Boom', 'fingerprint' => 'boom', 'attributes' => ['user.id' => $user]];
        foreach ([['alice', 'bob'], ['alice', 'carol']] as $index => $users) {
            app(TelemetryIngestor::class)->ingest($environment, "batch-{$index}", array_map($exception, $users), new IngestContext);
        }

        $issue = Issue::query()->sole();
        $this->assertSame([4, 3], [$issue->occurrences, $issue->affected_users]);
    }
}
