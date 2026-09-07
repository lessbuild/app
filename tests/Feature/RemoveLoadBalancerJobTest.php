<?php

namespace Tests\Feature;

use App\Jobs\RemoveLoadBalancerJob;
use App\Models\Server;
use App\Models\User;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $server = User::factory()->create()->servers()->create([
            'name' => 'Edge', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $process = null;
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturnUsing(function (string $script) use ($removeExit, $validateExit, $reloadExit, &$process): Process {
            // Execute the generated shell with harmless replacements for every remote command.
            $commands = "rm() { echo removal; test \"\$1\" = -f && test \"\$2\" = -- && test \"\$3\" = /etc/caddy/websites/ha-42.conf || return 99; return {$removeExit}; }\n"
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
            (new RemoveLoadBalancerJob($server->id, 42))->handle($runner);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $shouldFail = $removeExit !== 0 || $validateExit !== 0 || $reloadExit !== 0;
        $this->assertSame($shouldFail, $failure !== null);
        $this->assertSame($expectedCommands, explode("\n", trim($process->getOutput())));
        $this->assertSame(! $shouldFail, $process->isSuccessful());
        if ($shouldFail) {
            $this->assertSame('Unable to remove load-balancer configuration 42 from server '.$server->id.'.', $failure->getMessage());
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
}
