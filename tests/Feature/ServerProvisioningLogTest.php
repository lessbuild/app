<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Scripts\Server\BaseScript;
use App\Scripts\Server\EndScript;
use App\Services\ProvisioningCallbackUrl;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServerProvisioningLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_attempt_scoped_callback_persists_and_replaces_bounded_output(): void
    {
        [, $server] = $this->server();
        config(['lessbuild.server_log_max_characters' => 10]);
        $callback = ProvisioningCallbackUrl::serverLog($server);

        $this->post(route('callbacks.server.log', $server), ['log' => 'unsigned'])
            ->assertForbidden();
        $this->post($callback, ['log' => 'first log'])
            ->assertNoContent();
        $this->post($callback, ['log' => 'new output'])
            ->assertNoContent();

        $snapshot = $server->logSnapshots()->sole();
        $this->assertSame('provisioning', $snapshot->type);
        $this->assertSame(ServerLogSnapshot::STATUS_READY, $snapshot->status);
        $this->assertSame('new output', $snapshot->log);
        $this->assertNotNull($snapshot->refreshed_at);

        $this->post($callback, ['log' => str_repeat('x', 11)])
            ->assertSessionHasErrors('log');
        $this->assertSame('new output', $snapshot->fresh()->log);

        $server->update(['provisioning_token' => (string) str()->uuid()]);
        $this->post($callback, ['log' => 'stale log'])->assertNoContent();
        $this->assertSame('new output', $snapshot->fresh()->log);
    }

    public function test_pending_and_failed_server_pages_show_persisted_provisioning_output_without_ssh(): void
    {
        [$user, $server] = $this->server();
        $server->logSnapshots()->create([
            'type' => 'provisioning',
            'status' => ServerLogSnapshot::STATUS_READY,
            'log' => "Installing packages\n<script>alert('xss')</script>",
            'refreshed_at' => now(),
        ]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSee('wire:poll.5s', false)
            ->assertSeeText('Installing packages')
            ->assertDontSee("<script>alert('xss')</script>", false)
            ->assertDontSee('Logs will be available when provisioning finishes.');

        $server->update([
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_failure_phase' => Server::FAILURE_REMOTE,
            'provisioning_error' => 'Provisioning failed',
        ]);
        $this->actingAs($user)->get(route('servers.show', $server->fresh()))
            ->assertSuccessful()
            ->assertSeeText('Installing packages')
            ->assertDontSee('wire:poll.5s', false);
    }

    public function test_generated_script_uploads_progress_and_failure_logs_with_safe_shell_syntax(): void
    {
        [, $server] = $this->server();
        config(['lessbuild.server_log_max_characters' => 12345]);
        $script = (new BaseScript)->script(0, $server)."\n".(new EndScript)->script(5, $server);

        $this->assertStringContainsString('tail -c 12345 -- "$LOG_FILE"', $script);
        $this->assertStringContainsString('--data-urlencode "log@$LOG_UPLOAD_FILE"', $script);
        $this->assertStringContainsString('uploadProvisioningLog', $script);
        $this->assertStringContainsString('trap provisioningFailed ERR', $script);
        $this->assertStringContainsString('exec > >(tee -a "$LOG_FILE") 2>&1', $script);
        $this->assertSame(3, substr_count($script, 'uploadProvisioningLog'));
        $this->assertStringContainsString('attempt='.urlencode($server->provisioning_token), $script);

        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    public function test_failure_callback_marks_the_latest_streamed_snapshot_failed(): void
    {
        [, $server] = $this->server();
        $this->post(ProvisioningCallbackUrl::serverLog($server), ['log' => 'apt failed here'])
            ->assertNoContent();

        $this->post(ProvisioningCallbackUrl::serverFailure($server), [
            'message' => 'Remote server provisioning failed',
            'exit_code' => 100,
        ])->assertNoContent();

        $snapshot = $server->logSnapshots()->sole();
        $this->assertSame(ServerLogSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame('apt failed here', $snapshot->log);
        $this->assertSame('Remote server provisioning failed (exit code 100)', $snapshot->error);
    }

    public function test_legacy_tokenless_server_log_callbacks_remain_compatible(): void
    {
        [, $server] = $this->server();
        $server->update(['provisioning_token' => null]);

        $this->post(URL::signedRoute('callbacks.server.log', $server), ['log' => 'legacy output'])
            ->assertNoContent();

        $this->assertSame('legacy output', $server->logSnapshots()->sole()->log);
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
            'provisioning_status' => Server::STATUS_PROVISIONING,
        ]);

        return [$user, $server];
    }
}
