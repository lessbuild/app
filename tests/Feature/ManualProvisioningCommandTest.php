<?php

namespace Tests\Feature;

use App\Jobs\Server\InitialiseServerJob;
use App\Jobs\Web\AddWebsiteJob;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ManualProvisioningCommandTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('missingTargets')]
    public function test_missing_targets_fail_without_dispatching_jobs(string $command, string $argument, string $message): void
    {
        Bus::fake();

        $this->artisan($command, [$argument => '999999'])
            ->expectsOutputToContain($message)
            ->assertFailed();

        Bus::assertNothingDispatched();
    }

    public static function missingTargets(): array
    {
        return [
            'website' => ['lessbuild:server:add-website', 'website_id', 'Website not found.'],
            'server' => ['lessbuild:server:initialise', 'server_id', 'Server not found.'],
        ];
    }

    public function test_website_command_uses_synchronous_job_dispatch(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $server = $owner->servers()->create(['name' => 'Application host', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application', 'description' => 'Command fixture', 'url' => 'command.example.com',
            'environment' => '', 'database_password' => 'test-password',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        $this->artisan('lessbuild:server:add-website', ['website_id' => (string) $website->id])->assertSuccessful();

        Bus::assertDispatchedSync(AddWebsiteJob::class, fn (AddWebsiteJob $job): bool => $job->website->is($website));
        $this->assertSame(Website::STATUS_ACTIVE, $website->fresh()->provisioning_status);
    }

    public function test_server_command_preserves_synchronous_job_dispatch(): void
    {
        Bus::fake();
        $server = User::factory()->create()->servers()->create([
            'name' => 'Edge', 'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        $this->artisan('lessbuild:server:initialise', ['server_id' => (string) $server->id])->assertSuccessful();

        Bus::assertDispatchedSync(InitialiseServerJob::class, fn (InitialiseServerJob $job): bool => $job->server->is($server));
        $this->assertSame(Server::STATUS_ACTIVE, $server->fresh()->provisioning_status);
    }

    public function test_synchronous_website_failure_runs_the_jobs_failure_handler(): void
    {
        $owner = User::factory()->create();
        // The missing address makes Runner fail before it can open an SSH connection.
        $server = $owner->servers()->create(['name' => 'Unreachable host', 'public_ip' => null]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Command fixture',
            'url' => 'command.example.com', 'environment' => '', 'database_password' => 'test-password',
            'provisioning_status' => Website::STATUS_QUEUED,
        ]);
        $message = 'Server '.$server->id.' does not have a public IP address yet.';

        try {
            Artisan::call('lessbuild:server:add-website', ['website_id' => (string) $website->id]);
            $this->fail('The synchronous provisioning exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $website->refresh();
        $this->assertSame(Website::STATUS_FAILED, $website->provisioning_status);
        $this->assertSame($message, $website->provisioning_error);
        $this->assertSame($message, $website->logs()->where('type', Website::PROVISIONING_LOG_TYPE)->sole()->log);
    }
}
