<?php

namespace Tests\Feature;

use App\Modules\Deployer\Jobs\ApplyLoadBalancerJob;
use App\Modules\Deployer\Jobs\RemoveLoadBalancerJob;
use App\Modules\Deployer\Models\LoadBalancer;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ManagedSsh;
use App\Modules\Deployer\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RemoveLoadBalancerJobTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('commandOutcomes')]
    public function test_removal_stops_at_the_first_failed_command_and_reports_failure(
        int $removeExit,
        int $validateExit,
        int $reloadExit,
        array $expectedCommands,
    ): void {
        [$server, $loadBalancer] = $this->loadBalancer();
        $process = null;
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturnUsing(function (string $script) use ($removeExit, $validateExit, $reloadExit, $loadBalancer, &$process): Process {
            // Execute the generated shell with harmless replacements for every remote command.
            $configurationFile = '/etc/caddy/websites/ha-'.$loadBalancer->id.'.conf';
            $commands = "rm() { echo removal; test \"\$1\" = -f && test \"\$2\" = -- && test \"\$3\" = {$configurationFile} || return 99; return {$removeExit}; }\n"
                ."caddy() { echo validation; return {$validateExit}; }\n"
                ."systemctl() { echo reload; return {$reloadExit}; }\n";
            $process = new Process(['bash', '-c', $commands.$script]);
            $process->run();

            return $process;
        });
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->withArgs(fn (Server $target): bool => $target->is($server))->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        $failure = null;
        try {
            (new RemoveLoadBalancerJob($server->id, $loadBalancer->id))->handle($runner);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $shouldFail = $removeExit !== 0 || $validateExit !== 0 || $reloadExit !== 0;
        $this->assertSame($shouldFail, $failure !== null);
        $this->assertSame($expectedCommands, explode("\n", trim($process->getOutput())));
        $this->assertSame(! $shouldFail, $process->isSuccessful());
        if ($shouldFail) {
            $this->assertSame('Unable to remove load-balancer configuration '.$loadBalancer->id.' from server '.$server->id.'.', $failure->getMessage());
            $this->assertDatabaseHas('load_balancers', ['id' => $loadBalancer->id, 'status' => 'removing']);
        } else {
            $this->assertDatabaseMissing('load_balancers', ['id' => $loadBalancer->id]);
        }
    }

    public static function commandOutcomes(): array
    {
        return [
            'successful removal' => [0, 0, 0, ['removal', 'validation', 'reload']],
            'file removal fails' => [17, 0, 0, ['removal']],
            'configuration validation fails' => [0, 18, 0, ['removal', 'validation']],
            'service reload fails' => [0, 0, 19, ['removal', 'validation', 'reload']],
        ];
    }

    public function test_a_deleted_server_does_not_open_an_ssh_connection(): void
    {
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $runner->shouldNotReceive('create');

        (new RemoveLoadBalancerJob(999999, 42))->handle($runner);

        $this->assertDatabaseMissing('servers', ['id' => 999999]);
    }

    public function test_final_job_failure_keeps_the_balancer_retryable_with_a_safe_error(): void
    {
        [$server, $loadBalancer] = $this->loadBalancer();
        $job = new RemoveLoadBalancerJob($server->id, $loadBalancer->id);

        $job->failed(new RuntimeException('private_ssh_failure_output'));

        $this->assertDatabaseHas('load_balancers', [
            'id' => $loadBalancer->id,
            'status' => 'removal_failed',
            'last_error' => 'Remote load-balancer cleanup failed. Retry removal from Deployer.',
        ]);
    }

    public function test_apply_and_removal_jobs_share_a_per_balancer_remote_operation_lock(): void
    {
        $applyJob = new ApplyLoadBalancerJob(42);
        $removeJob = new RemoveLoadBalancerJob(7, 42);
        $applyLock = $applyJob->middleware()[0];
        $removeLock = $removeJob->middleware()[0];

        $this->assertSame($applyLock->getLockKey($applyJob), $removeLock->getLockKey($removeJob));
    }

    public function test_stale_removal_state_is_requeued_after_dispatch_crash_window(): void
    {
        [$server, $loadBalancer] = $this->loadBalancer();
        DB::connection('deployer')->table('load_balancers')
            ->where('id', $loadBalancer->id)
            ->update(['updated_at' => now()->subMinutes(11)]);
        Queue::fake();

        $this->artisan('buildpusher:load-balancers:reconcile-removals --limit=1')
            ->assertExitCode(0);

        Queue::assertPushed(RemoveLoadBalancerJob::class, fn (RemoveLoadBalancerJob $job): bool => $job->serverId === $server->id && $job->loadBalancerId === $loadBalancer->id);
    }

    public function test_reconciler_ignores_recent_or_finally_failed_removals(): void
    {
        [, $recent] = $this->loadBalancer();
        [, $failed] = $this->loadBalancer();
        $failed->update(['status' => 'removal_failed']);
        Queue::fake();

        $this->artisan('buildpusher:load-balancers:reconcile-removals')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('load_balancers', ['id' => $recent->id, 'status' => 'removing']);
        $this->assertDatabaseHas('load_balancers', ['id' => $failed->id, 'status' => 'removal_failed']);
    }

    public function test_removal_jobs_coalesce_reconciliation_dispatches_for_each_balancer(): void
    {
        $job = new RemoveLoadBalancerJob(7, 42);

        $this->assertSame('42', $job->uniqueId());
        $this->assertSame(900, $job->uniqueFor);
    }

    /** @return array{Server, LoadBalancer} */
    private function loadBalancer(): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => 'Edge',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Project',
            'slug' => 'project-'.str()->random(6),
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
        ]);
        $loadBalancer = $owner->currentOrganization->loadBalancers()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'hostname' => 'edge-'.str()->random(8).'.example.com',
            'health_path' => '/health',
            'created_by' => $owner->id,
            'status' => 'removing',
        ]);

        return [$server, $loadBalancer];
    }
}
