<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServerOperationsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Server $server;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->base = "/projects/{$this->project->id}/infrastructure/servers/{$this->server->id}";
    }

    public function test_commands_run_as_root_and_keep_an_encrypted_history(): void
    {
        $this->shell->reply("active (running)\n")->reply('', 3, 'Unit foo.service not found.');

        $this->actingAs($this->owner)->post("{$this->base}/commands", ['command' => 'systemctl status caddy'])->assertRedirect();
        $first = ServerCommandExecution::query()->sole();
        $this->assertSame('succeeded', $first->status);
        $this->assertSame('active (running)', $first->output);
        $this->assertSame(0, $first->exit_code);
        $this->assertSame($this->owner->id, $first->user_id);
        $this->assertStringNotContainsString('systemctl', (string) $first->getRawOriginal('command'));

        $this->actingAs($this->owner)->post("{$this->base}/commands/{$first->id}/rerun")->assertRedirect();
        $rerun = ServerCommandExecution::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $rerun->status);
        $this->assertSame(3, $rerun->exit_code);
        $this->assertSame('Unit foo.service not found.', $rerun->output);
        $this->assertSame($first->id, $rerun->rerun_from_execution_id);
        $this->assertSame(['systemctl status caddy', 'systemctl status caddy'], array_column($this->shell->ran, 'command'));

        $this->actingAs($this->owner)->get("{$this->base}/commands?output={$rerun->id}")->assertOk()->assertSee('Unit foo.service not found.')->assertSee('systemctl status caddy');
        $this->actingAs($this->owner)->get("{$this->base}/commands?status=failed")->assertOk()->assertSee('exit 3')->assertDontSee('exit 0');
        $csv = $this->actingAs($this->owner)->get("{$this->base}/commands/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('"systemctl status caddy",failed,'.$first->id.',3', $csv);

        $this->actingAs($this->owner)->delete("{$this->base}/commands/{$first->id}")->assertRedirect();
        $this->assertModelMissing($first);
    }

    public function test_one_command_at_a_time_and_only_on_active_servers(): void
    {
        $queued = new ServerCommandExecution;
        $queued->forceFill(['server_id' => $this->server->id, 'command' => 'sleep 60', 'status' => 'queued'])->save();

        $this->actingAs($this->owner)->post("{$this->base}/commands", ['command' => 'uptime'])->assertSessionHasErrors(['command' => 'Wait for the current command to finish first.']);
        $this->actingAs($this->owner)->post("{$this->base}/commands/{$queued->id}/rerun")->assertSessionHasErrors('command');
        $this->actingAs($this->owner)->delete("{$this->base}/commands/{$queued->id}")->assertSessionHas('status', 'Queued or running commands can’t be deleted.');
        $this->actingAs($this->owner)->post("{$this->base}/commands/{$queued->id}/cancel")->assertSessionHas('status', 'Command canceled.');
        $this->assertSame('canceled', $this->reload($queued)->status);

        $this->server->forceFill(['provisioning_status' => Server::STATUS_PROVISIONING])->save();
        $this->actingAs($this->owner)->post("{$this->base}/commands", ['command' => 'uptime'])->assertSessionHasErrors(['command' => 'Commands can run once provisioning finishes.']);
        $this->assertSame([], $this->shell->ran);
    }

    public function test_viewers_can_look_but_not_run_commands(): void
    {
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);

        $this->actingAs($viewer)->get("{$this->base}/commands")->assertOk()->assertDontSee('Run as root');
        $this->actingAs($viewer)->post("{$this->base}/commands", ['command' => 'rm -rf /'])->assertForbidden();
        $this->actingAs($viewer)->get("{$this->base}/commands/export")->assertForbidden();
        $this->assertDatabaseCount('server_command_executions', 0);
    }

    public function test_old_finished_commands_are_pruned(): void
    {
        foreach ([['succeeded', 200], ['running', 200], ['failed', 10]] as [$status, $days]) {
            $execution = new ServerCommandExecution;
            $execution->forceFill(['server_id' => $this->server->id, 'command' => 'x', 'status' => $status, 'created_at' => now()->subDays($days)])->save();
        }

        $this->command('servers:prune-commands')->expectsOutput('Pruned 1 server commands older than 180 days.')->assertSuccessful();
        $this->assertSame(['running', 'failed'], ServerCommandExecution::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_logs_metrics_and_diagnostics_are_read_over_ssh(): void
    {
        $this->shell->reply("Start-Date: 2026-09-01\n");
        $this->actingAs($this->owner)->post("{$this->base}/logs/apt")->assertRedirect("{$this->base}?log=apt");
        $this->actingAs($this->owner)->get("{$this->base}?log=apt")->assertOk()->assertSee('Start-Date: 2026-09-01');
        $this->assertStringContainsString('/var/log/apt/history.log', $this->shell->ran[0]['command']);

        $this->shell->reply("load_1m=0.52\nload_5m=0.40\nload_15m=0.30\nmemory_percent=61\ndisk_percent=45\nuptime_seconds=864000\ncpu_percent=37\nprocess_count=120\n");
        $this->command('servers:collect-metrics')->expectsOutput('Queued metrics for 1 servers.')->assertSuccessful();
        $metric = $this->server->metrics()->sole();
        $this->assertSame([37, 61, 45, 120], [$metric->cpu_percent, $metric->memory_percent, $metric->disk_percent, $metric->process_count]);
        $this->actingAs($this->owner)->get($this->base)->assertOk()->assertSee('CPU use over the last 24 hours')->assertSee('37%');

        $this->shell->reply("bp_diag_version=1\nuid=0\narchitecture=x86_64\nphp_version=8.4.1\nstorage_path=present\nstorage_writable=yes\ndisk_percent=95\nload_1m=0.10\nmemory_percent=40\nprocess_count=88\n");
        $this->actingAs($this->owner)->post("{$this->base}/diagnostics")->assertRedirect();
        $snapshot = $this->server->diagnosticSnapshot()->sole();
        $this->assertSame('ready', $snapshot->status);
        $checks = array_column($snapshot->checks ?? [], 'passed', 'name');
        $this->assertTrue($checks['PHP runtime']);
        $this->assertFalse($checks['Disk utilization']);
        $this->actingAs($this->owner)->get($this->base)->assertSee('Used 95%')->assertSee('PHP 8.4.1 available');

        $this->shell->reply('garbage');
        $this->actingAs($this->owner)->post("{$this->base}/diagnostics");
        $this->assertSame(['failed', 'response'], [$this->reload($snapshot)->status, $this->reload($snapshot)->failure_stage]);

        $this->server->forceFill(['ssh_host_key' => null])->save();
        $this->actingAs($this->owner)->post("{$this->base}/diagnostics");
        $this->assertSame('host_identity', $this->reload($snapshot)->failure_stage);
        $this->assertCount(4, $this->shell->ran);
    }
}
