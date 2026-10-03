<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Jobs\Infrastructure\SyncServerTask;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerFirewallRule;
use App\Models\ServerProcess;
use App\Models\ServerService;
use App\Models\User;
use App\Services\Infrastructure\ServerShell;
use App\Services\Infrastructure\ServerTaskScripts;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServerTasksTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The project the server's page is opened in.
     *
     * @var Project
     */
    private Project $project;

    /**
     * The project's owner.
     *
     * @var User
     */
    private User $owner;

    /**
     * The server tasks run on.
     *
     * @var Server
     */
    private Server $server;

    /**
     * The server page's address.
     *
     * @var string
     */
    private string $base;

    /**
     * Set up an active server in a project, with its owner signed in.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->server = Server::factory()->create(['name' => 'web-1', 'account_id' => $this->project->account_id, 'provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id, 'provisioning_status' => 'active']);
        $this->base = "/projects/{$this->project->id}/infrastructure/servers/{$this->server->id}";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Get the last command run on the server, with shell quoting undone.
     *
     * @return string
     */
    private function lastCommand(): string
    {
        return str_replace("'\\''", "'", (string) (collect($this->shell->ran)->last()['command'] ?? ''));
    }

    /**
     * Check cron jobs: validated, written to /etc/cron.d as the chosen user with % escaped, changed, and removed.
     *
     * @return void
     */
    public function test_cron_jobs_are_written_to_the_server_and_removed(): void
    {
        $this->actingAs($this->owner)->get("{$this->base}?tab=cron")->assertOk()->assertSee(__('Add a cron job'));
        $this->actingAs($this->owner)->post("{$this->base}/cron-jobs", ['command' => 'php artisan schedule:run', 'user' => 'web-1', 'frequency' => 'every minute'])->assertSessionHasErrors('frequency');
        $this->actingAs($this->owner)->post("{$this->base}/cron-jobs", ['command' => "echo hi\nrm -rf /", 'user' => 'web-1', 'frequency' => '* * * * *'])->assertSessionHasErrors('command');
        $this->actingAs($this->owner)->post("{$this->base}/cron-jobs", ['command' => 'date +%F', 'user' => 'mallory', 'frequency' => '* * * * *'])->assertSessionHasErrors('user');

        $this->actingAs($this->owner)->post("{$this->base}/cron-jobs", ['command' => 'php /home/web-1/shop/current/artisan schedule:run && date +%F', 'user' => 'web-1', 'frequency' => '* * * * *'])
            ->assertRedirect("{$this->base}?tab=cron");
        $job = ServerCronJob::query()->sole();
        $this->assertSame('active', $job->status);
        preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d > '\\/etc\\/cron.d\\/buildpusher-cron-{$job->id}.buildpusher-new'/", $this->lastCommand(), $file);
        $contents = base64_decode($file[1] ?? '', true);
        $this->assertIsString($contents);
        $this->assertStringContainsString('* * * * * web-1 php /home/web-1/shop/current/artisan schedule:run && date +\\%F >> /home/web-1/.buildpusher-cron-'.$job->id.'.log 2>&1', $contents);
        $this->actingAs($this->owner)->get("{$this->base}?tab=cron")->assertSee('Every minute')->assertSee(__('In place'));

        $this->actingAs($this->owner)->put("{$this->base}/cron-jobs/{$job->id}", ['command' => 'php artisan inspire', 'user' => 'root', 'frequency' => '0 3 * * *'])->assertRedirect();
        $this->assertSame(['0 3 * * *', 'root', 'active'], [$job->refresh()->frequency, $job->user, $job->status]);

        $this->actingAs($this->owner)->delete("{$this->base}/cron-jobs/{$job->id}")->assertRedirect();
        $this->assertSame("rm -f '/etc/cron.d/buildpusher-cron-{$job->id}'", $this->lastCommand());
        $this->assertSame(0, ServerCronJob::query()->count());
        $this->assertSame(['server_task.saved', 'server_task.saved', 'server_task.removed'], AuditEntry::query()->orderBy('id')->pluck('action')->map(fn ($action) => $action->value)->all());
    }

    /**
     * Check processes: a Supervisor program with the chosen copies, user and folder, restarted, and a failure
     * recorded with the server's error.
     *
     * @return void
     */
    public function test_processes_run_under_supervisor(): void
    {
        $this->actingAs($this->owner)->post("{$this->base}/processes", ['name' => 'Queue worker', 'command' => 'php artisan queue:work --tries=3', 'directory' => '/home/web-1/shop/current/', 'user' => 'web-1', 'processes' => 3, 'stop_wait_seconds' => 60])->assertRedirect("{$this->base}?tab=processes");
        $process = ServerProcess::query()->sole();
        $command = $this->lastCommand();
        $this->assertStringContainsString('apt-get install -y -qq supervisor', $command);
        $this->assertStringContainsString("supervisorctl update 'bp-process-{$process->id}'", $command);
        preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d > '\\/etc\\/supervisor\\/conf.d\\/bp-process-{$process->id}.conf.buildpusher-new'/", $command, $file);
        $conf = (string) base64_decode($file[1] ?? '', true);
        foreach (['command=php artisan queue:work --tries=3', 'directory=/home/web-1/shop/current', 'user=web-1', 'numprocs=3', 'stopwaitsecs=60', 'autorestart=true'] as $line) {
            $this->assertStringContainsString($line, $conf);
        }

        $this->actingAs($this->owner)->post("{$this->base}/processes/{$process->id}/restart")->assertRedirect();
        $this->assertSame("supervisorctl restart 'bp-process-{$process->id}:*'", $this->lastCommand());

        // On a real queue the job retries, then records the server's error.
        Queue::fake([SyncServerTask::class]);
        $this->shell->reply('', 1, 'ERROR (no such group)');
        $this->actingAs($this->owner)->put("{$this->base}/processes/{$process->id}", ['name' => 'Queue worker', 'command' => 'php artisan queue:work', 'user' => 'web-1', 'processes' => 1])->assertRedirect();
        Queue::assertPushed(SyncServerTask::class, function (SyncServerTask $job): bool {
            try {
                $job->handle(app(ServerShell::class), app(ServerTaskScripts::class));
            } catch (RuntimeException $exception) {
                $job->failed($exception);
            }

            return true;
        });
        $this->assertSame('failed', $process->refresh()->status);
        $this->assertStringContainsString('no such group', (string) $process->error);
        $this->actingAs($this->owner)->get("{$this->base}?tab=processes")->assertSee(__('Failed'))->assertSee('no such group');
    }

    /**
     * Check firewall rules: ports and sources are validated, opened with ufw, and closed again; viewers can't.
     *
     * @return void
     */
    public function test_firewall_rules_open_and_close_ports(): void
    {
        foreach ([['port' => '70000'], ['port' => '9000:8000'], ['port' => '22; reboot'], ['source' => '10.0.0.0/99'], ['source' => 'example.com']] as $bad) {
            $this->actingAs($this->owner)->post("{$this->base}/firewall-rules", ['name' => 'Bad', 'port' => '7700', 'protocol' => 'tcp', ...$bad])->assertSessionHasErrors(array_keys($bad)[0]);
        }
        $this->actingAs($this->owner)->post("{$this->base}/firewall-rules", ['name' => 'Meilisearch', 'port' => '7700', 'protocol' => 'tcp', 'source' => '10.0.0.0/16'])->assertRedirect("{$this->base}?tab=firewall");
        $rule = ServerFirewallRule::query()->sole();
        $this->assertSame("ufw allow proto 'tcp' from '10.0.0.0/16' to any port '7700' comment 'buildpusher rule {$rule->id}'", $this->lastCommand());
        $this->actingAs($this->owner)->delete("{$this->base}/firewall-rules/{$rule->id}")->assertRedirect();
        $this->assertSame("ufw delete allow proto 'tcp' from '10.0.0.0/16' to any port '7700' || true", $this->lastCommand());

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->post("{$this->base}/firewall-rules", ['name' => 'Mine', 'port' => '8080', 'protocol' => 'tcp'])->assertForbidden();
        $this->actingAs($viewer)->get($this->base)->assertOk()->assertDontSee(__('Firewall'));
    }

    /**
     * Check one-click services: installed with a generated key, listening on the server or also its private IP, the
     * key shown to people who may run commands, and uninstalled.
     *
     * @return void
     */
    public function test_services_install_with_a_generated_key_and_can_be_shared_privately(): void
    {
        $this->actingAs($this->owner)->post("{$this->base}/services", ['kind' => 'mongodb'])->assertSessionHasErrors('kind');
        $this->actingAs($this->owner)->post("{$this->base}/services", ['kind' => 'redis', 'listen' => 'private'])->assertSessionHasErrors('listen');

        $this->actingAs($this->owner)->post("{$this->base}/services", ['kind' => 'meilisearch', 'listen' => 'local'])->assertRedirect("{$this->base}?tab=services");
        $service = ServerService::query()->sole();
        $this->assertSame([40, 'active', 7700], [strlen($service->secret), $service->status, $service->port]);
        $this->assertNotSame($service->secret, $service->getRawOriginal('secret'), 'The key is stored encrypted.');
        $command = $this->lastCommand();
        $this->assertStringContainsString('releases/latest/download/meilisearch-linux-${ARCH}', $command);
        preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d > '\\/etc\\/systemd\\/system\\/meilisearch.service.new'/", $command, $unit);
        $this->assertStringContainsString('--http-addr 127.0.0.1:7700', (string) base64_decode($unit[1] ?? '', true));
        $this->actingAs($this->owner)->get("{$this->base}?tab=services")->assertSee('http://127.0.0.1:7700')->assertSee($service->secret);

        $this->server->forceFill(['private_ip' => '10.10.0.5'])->save();
        $this->actingAs($this->owner)->post("{$this->base}/services", ['kind' => 'redis', 'listen' => 'private'])->assertRedirect();
        preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d > '\\/etc\\/redis\\/conf.d\\/buildpusher.conf.new'/", $this->lastCommand(), $conf);
        $redis = ServerService::query()->where('kind', 'redis')->sole();
        $this->assertStringContainsString("bind 127.0.0.1 10.10.0.5\nport 6379\nrequirepass {$redis->secret}", (string) base64_decode($conf[1] ?? '', true));

        $this->actingAs($this->owner)->delete("{$this->base}/services/{$service->id}")->assertRedirect();
        $this->assertStringContainsString('systemctl disable --now meilisearch', $this->lastCommand());
        $this->assertFalse(ServerService::query()->whereKey($service->id)->exists());
    }

    /**
     * Check that trusting the private network lets in the account's other servers in the same region only, and that
     * turning it off removes exactly those rules.
     *
     * @return void
     */
    public function test_the_private_network_trusts_sibling_servers(): void
    {
        $this->actingAs($this->owner)->put("{$this->base}/private-network", ['trust' => '1'])->assertSessionHasErrors('trust');
        $this->server->forceFill(['private_ip' => '10.10.0.5', 'region' => 'fra1'])->save();
        $sibling = Server::factory()->create(['name' => 'db-1', 'account_id' => $this->project->account_id, 'provider_id' => $this->server->provider_id, 'region' => 'fra1', 'private_ip' => '10.10.0.9']);
        Server::factory()->create(['account_id' => $this->project->account_id, 'provider_id' => $this->server->provider_id, 'region' => 'nyc1', 'private_ip' => '10.20.0.9']);
        $this->actingAs($this->owner)->post("{$this->base}/firewall-rules", ['name' => 'Office', 'port' => '5432', 'protocol' => 'tcp', 'source' => '203.0.113.7'])->assertRedirect();

        $this->actingAs($this->owner)->put("{$this->base}/private-network", ['trust' => '1'])->assertRedirect("{$this->base}?tab=firewall");
        $this->assertTrue($this->server->refresh()->trust_private_network);
        $trusted = ServerFirewallRule::query()->where('name', 'like', 'Private network:%')->sole();
        $this->assertSame(['10.10.0.9', '1:65535'], [$trusted->source, $trusted->port]);
        $this->assertStringContainsString("from '10.10.0.9' to any port '1:65535'", $this->lastCommand());

        $this->actingAs($this->owner)->put("{$this->base}/private-network", ['trust' => '0'])->assertRedirect();
        $this->assertSame(['Office'], ServerFirewallRule::query()->pluck('name')->all());
        $this->assertSame('db-1', $sibling->name);
    }

    /**
     * Check that process presets fill the Add a process form.
     *
     * @return void
     */
    public function test_process_presets_fill_the_form(): void
    {
        $this->actingAs($this->owner)->get("{$this->base}?tab=processes&preset=horizon&dialog=add-process")->assertOk()
            ->assertSee('value="php artisan horizon"', false)->assertSee('data-modal-initial-open="true"', false);
    }
}
