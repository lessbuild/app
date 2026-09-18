<?php

namespace Tests\Feature;

use App\Enums\OperationalDiagnosticCategory;
use App\Enums\ServerDiagnosticFailureStage;
use App\Exceptions\ServerDiagnosticException;
use App\Http\Livewire\ServerShow;
use App\Jobs\Server\RunServerDiagnosticJob;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerDiagnosticSnapshot;
use App\Models\User;
use App\Services\ManagedSsh;
use App\Services\Runner;
use App\Services\ServerDiagnosticOutputParser;
use App\Services\ServerDiagnosticProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServerDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_queue_one_fixed_diagnostic_without_serializing_credentials(): void
    {
        Queue::fake();
        [$user, $server] = $this->server();

        Livewire::actingAs($user)
            ->test(ServerShow::class, ['server' => $server])
            ->call('runDiagnostics')
            ->assertHasNoErrors();

        $snapshot = $server->diagnosticSnapshot()->sole();
        $this->assertSame(ServerDiagnosticSnapshot::STATUS_QUEUED, $snapshot->status);
        $this->assertSame(1, $snapshot->attempt);

        Queue::assertPushed(RunServerDiagnosticJob::class, function (RunServerDiagnosticJob $job) use ($server): bool {
            $serialized = serialize($job);

            $this->assertSame($server->diagnosticSnapshot()->value('id'), $job->snapshotId);
            $this->assertStringNotContainsString($server->ssh_private_key, $serialized);
            $this->assertStringNotContainsString('mysql-secret', $serialized);

            return true;
        });
    }

    public function test_a_missing_pinned_identity_fails_without_opening_ssh_or_queueing_work(): void
    {
        Queue::fake();
        [$user, $server] = $this->server();
        $server->update(['ssh_host_key' => null]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        Livewire::actingAs($user)
            ->test(ServerShow::class, ['server' => $server])
            ->call('runDiagnostics')
            ->assertHasErrors(['diagnostics' => 'A pinned SSH host identity is required before diagnostics can run.']);

        $snapshot = $server->diagnosticSnapshot()->sole();
        $this->assertSame(ServerDiagnosticSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame(ServerDiagnosticFailureStage::HostIdentity->value, $snapshot->failure_stage);
        Queue::assertNothingPushed();
    }

    public function test_a_foreign_server_is_denied_before_a_diagnostic_snapshot_or_job_exists(): void
    {
        Queue::fake();
        [, $server] = $this->server();

        Livewire::actingAs(User::factory()->create())
            ->test(ServerShow::class, ['server' => $server])
            ->assertForbidden();

        $this->assertDatabaseMissing('server_diagnostic_snapshots', ['server_id' => $server->id]);
        Queue::assertNothingPushed();
    }

    public function test_a_second_request_does_not_queue_an_unexpired_active_snapshot(): void
    {
        Queue::fake();
        [$user, $server] = $this->server();
        $component = Livewire::actingAs($user)->test(ServerShow::class, ['server' => $server]);

        $component->call('runDiagnostics')->assertHasNoErrors();
        $component->call('runDiagnostics')->assertHasNoErrors();

        Queue::assertPushedTimes(RunServerDiagnosticJob::class, 1);
        $this->assertSame(1, $server->diagnosticSnapshot()->value('attempt'));
    }

    public function test_an_expired_snapshot_is_replaced_with_a_new_attempt(): void
    {
        Queue::fake();
        [$user, $server] = $this->server();
        $oldToken = (string) Str::uuid();
        $server->diagnosticSnapshot()->create([
            'status' => ServerDiagnosticSnapshot::STATUS_RUNNING,
            'attempt' => 1,
            'attempt_token' => $oldToken,
            'lease_expires_at' => now()->subMinute(),
            'started_at' => now()->subMinutes(2),
        ]);

        Livewire::actingAs($user)
            ->test(ServerShow::class, ['server' => $server])
            ->call('runDiagnostics')
            ->assertHasNoErrors();

        $snapshot = $server->diagnosticSnapshot()->firstOrFail();
        $this->assertSame(ServerDiagnosticSnapshot::STATUS_QUEUED, $snapshot->status);
        $this->assertSame(2, $snapshot->attempt);
        $this->assertNotSame($oldToken, $snapshot->attempt_token);
        Queue::assertPushedTimes(RunServerDiagnosticJob::class, 1);
    }

    public function test_parser_accepts_only_the_fixed_bounded_scalar_contract(): void
    {
        $values = (new ServerDiagnosticOutputParser)->parse($this->diagnosticOutput());

        $this->assertSame(1, $values['version']);
        $this->assertSame(0, $values['uid']);
        $this->assertSame('x86_64', $values['architecture']);
        $this->assertSame('8.5.10', $values['php_version']);
        $this->assertSame(42, $values['disk_percent']);
        $this->assertSame(0.25, $values['load_1m']);
        $this->assertSame(37, $values['memory_percent']);
        $this->assertSame(82, $values['process_count']);
    }

    public function test_parser_rejects_unknown_fields(): void
    {
        $parser = new ServerDiagnosticOutputParser;

        $this->expectException(\InvalidArgumentException::class);
        $parser->parse($this->diagnosticOutput()."secret=not-allowed\n");
    }

    public function test_parser_rejects_oversized_output(): void
    {
        config(['lessbuild.server_diagnostic_output_max_characters' => 10]);

        $this->expectException(\InvalidArgumentException::class);
        (new ServerDiagnosticOutputParser)->parse(str_repeat('x', 11));
    }

    public function test_probe_uses_the_fixed_script_and_returns_categorized_safe_checks(): void
    {
        [, $server] = $this->server();
        $runner = $this->runner($this->diagnosticOutput());
        $probe = new ServerDiagnosticProbe($runner, new ServerDiagnosticOutputParser);

        $report = $probe->handle($server);

        $this->assertCount(9, $report->checks);
        $this->assertTrue($report->passed());
        $this->assertSame('PHP runtime', $report->checks[4]->name);
        $this->assertSame('PHP 8.5.10 available', $report->checks[4]->detail);
        $this->assertCount(2, $report->forCategory(OperationalDiagnosticCategory::Connectivity));
        $this->assertStringNotContainsString('.env', ServerDiagnosticProbe::SCRIPT);
        $this->assertStringNotContainsString('QueueServerCommandAction', ServerDiagnosticProbe::SCRIPT);
    }

    public function test_probe_requires_a_pinned_identity_before_calling_the_runner(): void
    {
        [, $server] = $this->server();
        $server->update(['ssh_host_key' => null]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');

        try {
            (new ServerDiagnosticProbe($runner, new ServerDiagnosticOutputParser))->handle($server);
            $this->fail('A missing host identity should prevent the probe.');
        } catch (ServerDiagnosticException $exception) {
            $this->assertSame(ServerDiagnosticFailureStage::HostIdentity, $exception->stage);
            $this->assertStringNotContainsString('private-key', $exception->getMessage());
        }
    }

    public function test_probe_sanitizes_transport_errors(): void
    {
        [, $server] = $this->server();
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->with(false)->andThrow(new RuntimeException('private-key-secret'));

        try {
            (new ServerDiagnosticProbe($runner, new ServerDiagnosticOutputParser))->handle($server);
            $this->fail('The transport error should be converted into a diagnostic exception.');
        } catch (ServerDiagnosticException $exception) {
            $this->assertSame(ServerDiagnosticFailureStage::Transport, $exception->stage);
            $this->assertSame('Unable to connect to the server for diagnostics.', $exception->getMessage());
        }
    }

    public function test_job_persists_a_ready_typed_report_without_remote_output(): void
    {
        [, $server] = $this->server();
        $snapshot = $this->queuedSnapshot($server);
        $runner = $this->runner($this->diagnosticOutput());
        $job = new RunServerDiagnosticJob($snapshot->id, $snapshot->attempt_token);

        $job->handle(new ServerDiagnosticProbe($runner, new ServerDiagnosticOutputParser));

        $snapshot->refresh();
        $this->assertSame(ServerDiagnosticSnapshot::STATUS_READY, $snapshot->status);
        $this->assertNull($snapshot->error);
        $this->assertNull($snapshot->lease_expires_at);
        $this->assertTrue($snapshot->report()?->passed());
        $this->assertArrayNotHasKey('output', $snapshot->report()?->toLegacyChecks()[0] ?? []);
    }

    public function test_job_marks_an_invalid_remote_response_failed_without_retaining_the_response(): void
    {
        [, $server] = $this->server();
        $snapshot = $this->queuedSnapshot($server);
        $runner = $this->runner("unexpected=remote-secret\n");
        $job = new RunServerDiagnosticJob($snapshot->id, $snapshot->attempt_token);

        $job->handle(new ServerDiagnosticProbe($runner, new ServerDiagnosticOutputParser));

        $snapshot->refresh();
        $this->assertSame(ServerDiagnosticSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame(ServerDiagnosticFailureStage::Response->value, $snapshot->failure_stage);
        $this->assertSame('The server returned an invalid diagnostic response.', $snapshot->error);
        $this->assertStringNotContainsString('remote-secret', json_encode($snapshot->checks, JSON_THROW_ON_ERROR));
    }

    public function test_transport_retry_requeues_then_final_failure_is_safe(): void
    {
        [, $server] = $this->server();
        $snapshot = $this->queuedSnapshot($server);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->with(false)->andThrow(new RuntimeException('remote-secret-output'));
        $job = new RunServerDiagnosticJob($snapshot->id, $snapshot->attempt_token);
        $probe = new ServerDiagnosticProbe($runner, new ServerDiagnosticOutputParser);

        try {
            $job->handle($probe);
            $this->fail('A transport failure should be retried by the queue.');
        } catch (ServerDiagnosticException $exception) {
            $snapshot->refresh();
            $this->assertSame(ServerDiagnosticSnapshot::STATUS_QUEUED, $snapshot->status);
            $job->failed($exception);
        }

        $snapshot->refresh();
        $this->assertSame(ServerDiagnosticSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame(ServerDiagnosticFailureStage::Transport->value, $snapshot->failure_stage);
        $this->assertSame('Unable to complete the server diagnostic connection.', $snapshot->error);
        $this->assertStringNotContainsString('remote-secret-output', (string) $snapshot->error);
    }

    public function test_stale_job_cannot_claim_a_replaced_attempt(): void
    {
        [, $server] = $this->server();
        $snapshot = $this->queuedSnapshot($server);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $job = new RunServerDiagnosticJob($snapshot->id, (string) Str::uuid());

        $job->handle(new ServerDiagnosticProbe($runner, new ServerDiagnosticOutputParser));

        $this->assertSame(ServerDiagnosticSnapshot::STATUS_QUEUED, $snapshot->fresh()->status);
    }

    public function test_ready_snapshot_is_rendered_without_opening_ssh_or_exposing_raw_values(): void
    {
        [$user, $server] = $this->server();
        $server->diagnosticSnapshot()->create([
            'status' => ServerDiagnosticSnapshot::STATUS_READY,
            'attempt' => 1,
            'attempt_token' => (string) Str::uuid(),
            'checks' => [[
                'name' => 'PHP runtime',
                'category' => 'runtime',
                'passed' => true,
                'detail' => 'PHP 8.5.10 available',
            ]],
            'finished_at' => now(),
        ]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSeeText('Server diagnostics')
            ->assertSeeText('PHP runtime')
            ->assertSeeText('PHP 8.5.10 available')
            ->assertDontSee('private-key-secret', false)
            ->assertDontSee('wire:poll.5s', false);
    }

    public function test_completed_operational_evidence_is_collapsed_but_first_use_stays_discoverable(): void
    {
        [$user, $server] = $this->server();

        $initial = $this->actingAs($user)->get(route('servers.show', $server));
        $initialContent = $initial->getContent();
        $this->assertMatchesRegularExpression('/<details id="server-metrics"[^>]*\bopen\b[^>]*>/', $initialContent);
        $this->assertMatchesRegularExpression('/<details id="server-diagnostics"[^>]*\bopen\b[^>]*>/', $initialContent);

        $server->metrics()->create([
            'load_1m' => 0.25,
            'load_5m' => 0.5,
            'memory_percent' => 37,
            'disk_percent' => 42,
            'uptime_seconds' => 3600,
            'recorded_at' => now(),
        ]);
        $server->diagnosticSnapshot()->create([
            'status' => ServerDiagnosticSnapshot::STATUS_READY,
            'attempt' => 1,
            'attempt_token' => (string) Str::uuid(),
            'checks' => [[
                'name' => 'PHP runtime',
                'category' => 'runtime',
                'passed' => true,
                'detail' => 'PHP 8.5.10 available',
            ]],
            'finished_at' => now(),
        ]);

        $completed = $this->actingAs($user)->get(route('servers.show', $server));
        $completedContent = $completed->getContent();
        $this->assertDoesNotMatchRegularExpression('/<details id="server-metrics"[^>]*\bopen\b[^>]*>/', $completedContent);
        $this->assertDoesNotMatchRegularExpression('/<details id="server-diagnostics"[^>]*\bopen\b[^>]*>/', $completedContent);
    }

    private function queuedSnapshot(Server $server): ServerDiagnosticSnapshot
    {
        return $server->diagnosticSnapshot()->create([
            'status' => ServerDiagnosticSnapshot::STATUS_QUEUED,
            'attempt' => 1,
            'attempt_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinutes(3),
        ]);
    }

    private function server(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Production',
            'public_ip' => '192.0.2.10',
            'ssh_port' => 22,
            'ssh_private_key' => 'private-key',
            'ssh_host_key' => '192.0.2.10 ssh-ed25519 AAAAhost-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$user, $server];
    }

    private function runner(string $output): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getOutput')->once()->andReturn($output);
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->with(ServerDiagnosticProbe::SCRIPT)->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->with(false)->andReturn($ssh);

        return $runner;
    }

    private function diagnosticOutput(): string
    {
        return implode("\n", [
            'bp_diag_version=1',
            'uid=0',
            'architecture=x86_64',
            'php_version=8.5.10',
            'storage_path=present',
            'storage_writable=yes',
            'disk_percent=42',
            'load_1m=0.25',
            'memory_percent=37',
            'process_count=82',
        ])."\n";
    }
}
