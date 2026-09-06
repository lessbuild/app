<?php

namespace Tests\Feature;

use App\Jobs\Web\CreateWebsiteBackupJob;
use App\Jobs\Web\RestoreWebsiteBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupRestore;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Services\ManagedSsh;
use App\Services\ResticRepository;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ManagedBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_destination_credentials_are_encrypted_and_schedule_is_scoped(): void
    {
        [$owner, $website] = $this->infrastructure();
        $this->actingAs($owner)->post(route('backups.destinations.store'), $this->destinationPayload())->assertRedirect();
        $destination = $owner->currentOrganization->backupDestinations()->sole();

        $this->assertSame('access-key', $destination->access_key);
        $this->assertNotSame('access-key', DB::table('backup_destinations')->value('access_key'));
        $this->assertArrayNotHasKey('secret_key', $destination->toArray());

        $this->actingAs($owner)->post(route('backups.schedules.store'), [
            'website_id' => $website->id, 'backup_destination_id' => $destination->id,
            'frequency' => 'daily', 'run_at' => '02:30', 'retention_count' => 14,
        ])->assertRedirect();
        $schedule = $website->backupSchedules()->sole();
        $this->assertSame('02:30', substr($schedule->run_at, 0, 5));
        $this->assertSame(14, $schedule->retention_count);

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->delete(route('backups.schedules.destroy', $schedule))->assertForbidden();
    }

    public function test_remote_backup_records_restic_snapshot_and_size(): void
    {
        [$owner, $website] = $this->infrastructure();
        $destination = $this->destination($owner);
        $backup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_QUEUED,
        ]);
        $command = '';
        $runner = $this->runner("{\"message_type\":\"summary\",\"snapshot_id\":\"abcdef1234567890\",\"total_bytes_processed\":4096}\n", $command);

        (new CreateWebsiteBackupJob($backup->id))->handle($runner, app(ResticRepository::class));

        $backup->refresh();
        $this->assertSame(WebsiteBackup::STATUS_SUCCEEDED, $backup->status);
        $this->assertSame('abcdef1234567890', $backup->snapshot_id);
        $this->assertNotNull($backup->https_verified_at);
        $this->assertSame(4096, $backup->size_bytes);
        $this->assertStringContainsString('mysqldump --single-transaction', $command);
        $this->assertStringContainsString('restic backup --json', $command);
        $this->assertStringContainsString('RESTIC_PASSWORD=', $command);
    }

    public function test_restore_requires_exact_confirmation_and_queues_safety_restore(): void
    {
        Queue::fake();
        [$owner, $website] = $this->infrastructure();
        $backup = $website->backups()->create([
            'backup_destination_id' => $this->destination($owner)->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'abcdef1234567890',
        ]);

        $this->actingAs($owner)->post(route('backups.restore', $backup), ['confirmation' => 'wrong'])
            ->assertSessionHasErrors('confirmation');
        $this->actingAs($owner)->post(route('backups.restore', $backup), ['confirmation' => $website->name])
            ->assertSessionHas('success', 'Restore queued with automatic safety rollback.');
        $restore = $backup->restores()->sole();
        Queue::assertPushed(RestoreWebsiteBackupJob::class, fn (RestoreWebsiteBackupJob $job): bool => $job->restoreId === $restore->id);

        $command = '';
        $runner = $this->runner('restore complete', $command);
        (new RestoreWebsiteBackupJob($restore->id))->handle($runner, app(ResticRepository::class));
        $this->assertSame(BackupRestore::STATUS_SUCCEEDED, $restore->fresh()->status);
        $this->assertStringContainsString('rollback_restore()', $command);
        $this->assertStringContainsString('safety.sql', $command);
        $this->assertStringContainsString('restic restore', $command);
        $this->assertStringContainsString('php artisan down', $command);
        $this->assertStringContainsString('php artisan up', $command);
    }

    /** @return array{User, Website} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'DigitalOcean', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'token', 'description' => 'Cloud',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $provider->id, 'name' => 'Production', 'public_ip' => '203.0.113.20',
            'ssh_private_key' => 'private', 'mysql_root_password' => 'mysql-secret',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Website',
            'environment' => 'APP_KEY=secret', 'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $website];
    }

    private function destination(User $owner): BackupDestination
    {
        return $owner->currentOrganization->backupDestinations()->create([
            ...$this->destinationPayload(),
            'created_by' => $owner->id,
            'repository_password' => 'repository-secret',
        ]);
    }

    private function destinationPayload(): array
    {
        return [
            'name' => 'R2', 'endpoint' => 'https://storage.example.com', 'bucket' => 'buildpusher-backups',
            'region' => 'auto', 'access_key' => 'access-key', 'secret_key' => 'secret-key', 'path_prefix' => 'production',
        ];
    }

    private function runner(string $output, string &$command): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $process->shouldReceive('getOutput')->zeroOrMoreTimes()->andReturn($output);
        $process->shouldReceive('getErrorOutput')->zeroOrMoreTimes()->andReturn('');
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->with(Mockery::on(function (string $value) use (&$command): bool {
            $command = $value;

            return true;
        }))->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }
}
