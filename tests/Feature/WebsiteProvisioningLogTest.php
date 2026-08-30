<?php

namespace Tests\Feature;

use App\Actions\Web\AddWebsiteAction;
use App\Jobs\Web\AddWebsiteJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\ProvisioningCallbackUrl;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebsiteProvisioningLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_attempt_scoped_callback_persists_and_replaces_bounded_output(): void
    {
        [, , $website] = $this->resources();
        config(['lessbuild.website_log_max_characters' => 10]);
        $callback = ProvisioningCallbackUrl::websiteLog($website);

        $this->post(route('callbacks.website.log', $website), ['log' => 'unsigned'])
            ->assertForbidden();
        $this->post($callback, ['log' => 'first log'])->assertNoContent();
        $this->post($callback, ['log' => 'new output'])->assertNoContent();

        $log = $website->logs()->sole();
        $this->assertSame(Website::PROVISIONING_LOG_TYPE, $log->type);
        $this->assertSame('new output', $log->log);

        $this->post($callback, ['log' => str_repeat('x', 11)])
            ->assertSessionHasErrors('log');
        $this->assertSame('new output', $log->fresh()->log);

        $website->update(['provisioning_token' => (string) str()->uuid()]);
        $this->post($callback, ['log' => 'stale output'])->assertNoContent();
        $this->post($callback, ['log' => str_repeat('x', 11)])->assertNoContent();
        $this->assertSame('new output', $log->fresh()->log);
    }

    public function test_page_polls_and_escapes_output_during_provisioning_then_preserves_it(): void
    {
        [$user, , $website] = $this->resources();
        $website->logs()->create([
            'type' => Website::PROVISIONING_LOG_TYPE,
            'log' => "Creating database\n<script>alert('xss')</script>",
        ]);

        $this->actingAs($user)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('wire:poll.5s', false)
            ->assertSeeText('Creating database')
            ->assertDontSee("<script>alert('xss')</script>", false);

        $website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => 'Database creation failed',
        ]);

        $this->actingAs($user)->get(route('websites.show', $website->fresh()))
            ->assertSuccessful()
            ->assertSeeText('Creating database')
            ->assertDontSee('wire:poll.5s', false);
    }

    public function test_generated_background_script_uploads_progress_and_failure_logs(): void
    {
        [, , $website] = $this->resources();
        config(['lessbuild.website_log_max_characters' => 12345]);
        $script = null;
        $upload = Mockery::mock(Process::class);
        $upload->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $remote = Mockery::mock(Process::class);
        $remote->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $remote->shouldReceive('getOutput')->once()->andReturn("4321\n");
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('upload')
            ->once()
            ->withArgs(function (string $sourcePath) use (&$script): bool {
                $script = file_get_contents($sourcePath);

                return true;
            })
            ->andReturn($upload);
        $ssh->shouldReceive('execute')->once()->andReturn($remote);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->withArgs(fn (Server $server): bool => $server->is($website->server))->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        (new AddWebsiteAction($website, $runner))->handle();

        $this->assertNotNull($script);
        $this->assertStringContainsString('tail -c 12345 -- "$LOG_FILE"', $script);
        $this->assertStringContainsString('--data-urlencode "log@$LOG_UPLOAD_FILE"', $script);
        $this->assertStringContainsString('trap websiteProvisioningFailed ERR', $script);
        $this->assertStringContainsString('exec > >(tee -a "$LOG_FILE") 2>&1', $script);
        $this->assertSame(6, substr_count($script, 'uploadWebsiteProvisioningLog'));
        $this->assertStringContainsString('attempt='.urlencode($website->provisioning_token), $script);

        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    public function test_current_job_failure_is_persisted_but_stale_failure_is_ignored(): void
    {
        [, , $website] = $this->resources();
        $currentJob = new AddWebsiteJob($website);
        $currentJob->failed(new RuntimeException('SSH upload failed'));

        $website->refresh();
        $this->assertSame(Website::STATUS_FAILED, $website->provisioning_status);
        $this->assertSame('SSH upload failed', $website->provisioning_error);
        $this->assertSame('SSH upload failed', $website->logs()->sole()->log);

        $website->update([
            'provisioning_token' => (string) str()->uuid(),
            'provisioning_status' => Website::STATUS_QUEUED,
            'provisioning_error' => null,
        ]);
        $currentJob->failed(new RuntimeException('Late failure'));

        $this->assertSame(Website::STATUS_QUEUED, $website->fresh()->provisioning_status);
        $this->assertSame('SSH upload failed', $website->logs()->sole()->log);
    }

    public function test_final_log_upload_is_accepted_after_the_completion_callback(): void
    {
        [, , $website] = $this->resources();
        $statusCallback = ProvisioningCallbackUrl::websiteStatus($website);
        $logCallback = ProvisioningCallbackUrl::websiteLog($website);

        $this->post($statusCallback, ['status' => 3])->assertSuccessful();
        $this->assertSame(Website::STATUS_ACTIVE, $website->fresh()->provisioning_status);

        $this->post($logCallback, ['log' => 'Provisioning completed'])->assertNoContent();
        $this->assertSame('Provisioning completed', $website->logs()->sole()->log);
    }

    public function test_legacy_tokenless_log_callbacks_remain_compatible(): void
    {
        [, , $website] = $this->resources();
        $website->update(['provisioning_token' => null]);

        $this->post(URL::signedRoute('callbacks.website.log', $website), ['log' => 'legacy output'])
            ->assertNoContent();

        $this->assertSame('legacy output', $website->logs()->sole()->log);
    }

    private function resources(): array
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Production',
            'type' => ServerTypeEnum::app,
            'public_ip' => '192.0.2.10',
            'ssh_private_key' => 'private-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Customer Portal',
            'description' => 'Customer portal',
            'environment' => 'APP_ENV=production',
            'url' => 'portal.example.com',
            'database_password' => 'database-secret',
            'provisioning_status' => Website::STATUS_PROVISIONING,
        ]);

        return [$user, $server, $website];
    }
}
