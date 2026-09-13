<?php

namespace Tests\Feature;

use App\Jobs\Web\VerifyWebsiteBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupRestoreVerification;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Services\BackupRecoveryEvidenceQuery;
use App\Services\ManagedSsh;
use App\Services\ResticRepository;
use App\Services\Runner;
use App\Services\VerifyWebsiteBackupScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupRestoreVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_manager_can_queue_one_exact_snapshot_verification_and_denied_requests_have_no_side_effects(): void
    {
        [$owner, $website] = $this->infrastructure();
        $backup = $this->completedBackup($owner, $website);
        Queue::fake();

        $this->actingAs($owner)->post(route('backups.verify', $backup), ['confirmation' => 'wrong'])
            ->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('backup_restore_verifications', 0);
        Queue::assertNothingPushed();

        $this->actingAs($owner)->post(route('backups.verify', $backup), ['confirmation' => $website->name])
            ->assertSessionHas('success', 'Isolated restore verification queued.');

        $verification = $backup->verifications()->sole();
        $this->assertSame(BackupRestoreVerification::STATUS_QUEUED, $verification->status);
        $this->assertSame($backup->snapshot_id, $verification->snapshot_id);
        $this->assertSame(BackupRestoreVerification::TARGET_SAME_SERVER_TEMPORARY, $verification->target_type);
        $this->assertSame(BackupRestoreVerification::OVERWRITE_NEVER, $verification->overwrite_mode);
        Queue::assertPushed(VerifyWebsiteBackupJob::class, fn (VerifyWebsiteBackupJob $job): bool => $job->verificationId === $verification->id);

        $this->actingAs($owner)->post(route('backups.verify', $backup), ['confirmation' => $website->name])
            ->assertSessionHas('error', 'A recovery verification is already in progress for this backup.');
        $this->assertDatabaseCount('backup_restore_verifications', 1);
        Queue::assertPushed(VerifyWebsiteBackupJob::class, 1);

        [$foreignOwner, $foreignWebsite] = $this->infrastructure();
        $foreignBackup = $this->completedBackup($foreignOwner, $foreignWebsite);
        $this->actingAs($owner)->post(route('backups.verify', $foreignBackup), ['confirmation' => $foreignWebsite->name])
            ->assertForbidden();
        $this->assertDatabaseCount('backup_restore_verifications', 1);
        Queue::assertPushed(VerifyWebsiteBackupJob::class, 1);

        $developer = User::factory()->create(['current_organization_id' => $owner->current_organization_id]);
        $owner->currentOrganization->members()->attach($developer->id, ['role' => 'developer']);
        $this->actingAs($developer)->post(route('backups.verify', $backup), ['confirmation' => $website->name])
            ->assertForbidden();
        $this->assertDatabaseCount('backup_restore_verifications', 1);
        Queue::assertPushed(VerifyWebsiteBackupJob::class, 1);
    }

    public function test_successful_job_records_integrity_smoke_cleanup_and_safe_target_boundaries(): void
    {
        [$owner, $website] = $this->infrastructure();
        $backup = $this->completedBackup($owner, $website);
        $verification = $backup->verifications()->create([
            'requested_by' => $owner->id,
            'snapshot_id' => $backup->snapshot_id,
            'target_type' => BackupRestoreVerification::TARGET_SAME_SERVER_TEMPORARY,
            'overwrite_mode' => BackupRestoreVerification::OVERWRITE_NEVER,
            'status' => BackupRestoreVerification::STATUS_QUEUED,
            'integrity_status' => BackupRestoreVerification::CHECK_PENDING,
            'smoke_status' => BackupRestoreVerification::CHECK_PENDING,
            'cleanup_status' => BackupRestoreVerification::CLEANUP_PENDING,
        ]);
        $command = '';
        $runner = $this->runner($website->server, true, "BP_INTEGRITY_STATUS=passed\nBP_SMOKE_STATUS=passed\nBP_CLEANUP_STATUS=passed\n", $command);

        (new VerifyWebsiteBackupJob($verification->id))->handle(
            $runner,
            app(ResticRepository::class),
            app(VerifyWebsiteBackupScript::class),
        );

        $verification->refresh();
        $this->assertSame(BackupRestoreVerification::STATUS_SUCCEEDED, $verification->status);
        $this->assertSame(BackupRestoreVerification::CHECK_PASSED, $verification->integrity_status);
        $this->assertSame(BackupRestoreVerification::CHECK_PASSED, $verification->smoke_status);
        $this->assertSame(BackupRestoreVerification::CLEANUP_PASSED, $verification->cleanup_status);
        $this->assertNotNull($verification->completed_at);
        $this->assertNotNull($verification->duration_seconds);
        $this->assertStringContainsString("SNAPSHOT='{$backup->snapshot_id}'", $command);
        $this->assertStringContainsString('restic restore "$SNAPSHOT"', $command);
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS', $command);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS', $command);
        $this->assertStringContainsString('php artisan migrate:status', $command);
        $this->assertStringNotContainsString('php artisan down', $command);
        $this->assertStringNotContainsString('safety.sql', $command);
        $this->assertStringNotContainsString('shared/storage', $command);
        $this->assertArrayNotHasKey('snapshot_id', $verification->toArray());
        $this->assertArrayNotHasKey('error', $verification->toArray());
    }

    public function test_failed_verification_can_be_retried_without_reusing_the_failed_record(): void
    {
        [$owner, $website] = $this->infrastructure();
        $backup = $this->completedBackup($owner, $website);
        $failed = $backup->verifications()->create([
            'requested_by' => $owner->id,
            'snapshot_id' => $backup->snapshot_id,
            'target_type' => BackupRestoreVerification::TARGET_SAME_SERVER_TEMPORARY,
            'overwrite_mode' => BackupRestoreVerification::OVERWRITE_NEVER,
            'status' => BackupRestoreVerification::STATUS_FAILED,
            'integrity_status' => BackupRestoreVerification::CHECK_FAILED,
            'smoke_status' => BackupRestoreVerification::CHECK_PENDING,
            'cleanup_status' => BackupRestoreVerification::CLEANUP_FAILED,
            'failure_stage' => BackupRestoreVerification::STAGE_CLEANUP,
            'error' => 'The temporary verification target could not be cleaned up.',
            'completed_at' => now(),
        ]);
        Queue::fake();

        $this->actingAs($owner)->get(route('backups.index'))
            ->assertOk()
            ->assertSee('Retry verification');

        $this->actingAs($owner)->post(route('backups.verify', $backup), ['confirmation' => $website->name])
            ->assertSessionHas('success', 'Isolated restore verification queued.');

        $this->assertDatabaseCount('backup_restore_verifications', 2);
        $this->assertSame(BackupRestoreVerification::STATUS_FAILED, $failed->fresh()->status);
        $this->assertSame(BackupRestoreVerification::STATUS_QUEUED, $backup->verifications()->latest('id')->first()->status);
        Queue::assertPushed(VerifyWebsiteBackupJob::class, 1);
    }

    public function test_failed_job_records_the_bounded_failure_stage_without_remote_output(): void
    {
        [$owner, $website] = $this->infrastructure();
        $backup = $this->completedBackup($owner, $website);
        $verification = $this->queuedVerification($owner, $backup);
        $command = '';
        $runner = $this->runner(
            $website->server,
            false,
            "BP_FAILURE_STAGE=integrity\nBP_CLEANUP_STATUS=passed\nremote password=mysql-secret\n",
            $command,
        );

        try {
            (new VerifyWebsiteBackupJob($verification->id))->handle(
                $runner,
                app(ResticRepository::class),
                app(VerifyWebsiteBackupScript::class),
            );
            $this->fail('Expected the isolated verification job to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The restored database or storage did not pass integrity checks.', $exception->getMessage());
        }

        $verification->refresh();
        $this->assertSame(BackupRestoreVerification::STATUS_FAILED, $verification->status);
        $this->assertSame(BackupRestoreVerification::STAGE_INTEGRITY, $verification->failure_stage);
        $this->assertSame(BackupRestoreVerification::CHECK_FAILED, $verification->integrity_status);
        $this->assertSame(BackupRestoreVerification::CLEANUP_PASSED, $verification->cleanup_status);
        $this->assertSame('The restored database or storage did not pass integrity checks.', $verification->error);
        $this->assertStringNotContainsString('mysql-secret', (string) $verification->error);
    }

    public function test_job_fails_closed_when_the_retained_snapshot_changes_before_execution(): void
    {
        [$owner, $website] = $this->infrastructure();
        $backup = $this->completedBackup($owner, $website);
        $verification = $this->queuedVerification($owner, $backup);
        $backup->update(['snapshot_id' => 'fedcba9876543210']);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');

        try {
            (new VerifyWebsiteBackupJob($verification->id))->handle(
                $runner,
                app(ResticRepository::class),
                app(VerifyWebsiteBackupScript::class),
            );
            $this->fail('Expected changed snapshot identity to stop verification.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The retained snapshot changed or is no longer restorable.', $exception->getMessage());
        }

        $verification->refresh();
        $this->assertSame(BackupRestoreVerification::STATUS_FAILED, $verification->status);
        $this->assertSame(BackupRestoreVerification::STAGE_PREFLIGHT, $verification->failure_stage);
        $this->assertSame('Isolated restore verification prerequisites were not met.', $verification->error);
    }

    public function test_summary_reports_only_successful_isolated_verification_for_the_current_workspace(): void
    {
        [$owner, $website] = $this->infrastructure();
        $backup = $this->completedBackup($owner, $website);
        $verification = $backup->verifications()->create([
            'requested_by' => $owner->id,
            'snapshot_id' => $backup->snapshot_id,
            'status' => BackupRestoreVerification::STATUS_SUCCEEDED,
            'integrity_status' => BackupRestoreVerification::CHECK_PASSED,
            'smoke_status' => BackupRestoreVerification::CHECK_PASSED,
            'cleanup_status' => BackupRestoreVerification::CLEANUP_PASSED,
            'completed_at' => now()->subMinute(),
        ]);

        $summary = app(BackupRecoveryEvidenceQuery::class)->summary($owner->currentOrganization);

        $this->assertSame($verification->completed_at->toDateTimeString(), $summary->latestIndependentRecoveryVerificationAt?->toDateTimeString());
    }

    /** @return array{User, Website} */
    private function infrastructure(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $provider = $owner->providers()->create([
            'organization_id' => $owner->current_organization_id,
            'name' => 'DigitalOcean', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'token', 'description' => 'Cloud',
        ]);
        $server = $owner->servers()->create([
            'organization_id' => $owner->current_organization_id,
            'provider_id' => $provider->id, 'name' => 'Production', 'public_ip' => '203.0.113.20',
            'ssh_private_key' => 'private', 'mysql_root_password' => 'mysql-secret',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'organization_id' => $owner->current_organization_id,
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Website',
            'environment' => 'APP_KEY=secret', 'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $website];
    }

    private function completedBackup(User $owner, Website $website): WebsiteBackup
    {
        return $website->backups()->create([
            'backup_destination_id' => $this->destination($owner)->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'abcdef1234567890',
            'completed_at' => now()->subMinutes(2),
        ]);
    }

    private function queuedVerification(User $owner, WebsiteBackup $backup): BackupRestoreVerification
    {
        return $backup->verifications()->create([
            'requested_by' => $owner->id,
            'snapshot_id' => $backup->snapshot_id,
            'target_type' => BackupRestoreVerification::TARGET_SAME_SERVER_TEMPORARY,
            'overwrite_mode' => BackupRestoreVerification::OVERWRITE_NEVER,
            'status' => BackupRestoreVerification::STATUS_QUEUED,
            'integrity_status' => BackupRestoreVerification::CHECK_PENDING,
            'smoke_status' => BackupRestoreVerification::CHECK_PENDING,
            'cleanup_status' => BackupRestoreVerification::CLEANUP_PENDING,
        ]);
    }

    private function destination(User $owner): BackupDestination
    {
        return $owner->currentOrganization->backupDestinations()->create([
            'created_by' => $owner->id,
            'name' => 'R2', 'endpoint' => 'https://storage.example.com', 'bucket' => 'buildpusher-backups',
            'region' => 'auto', 'access_key' => 'access-key', 'secret_key' => 'secret-key',
            'repository_password' => 'repository-secret', 'path_prefix' => 'production',
        ]);
    }

    private function runner(Server $server, bool $successful, string $output, ?string &$command): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturn($successful);
        $process->shouldReceive('getOutput')->once()->andReturn($output);
        $process->shouldReceive('getErrorOutput')->zeroOrMoreTimes()->andReturn('');
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->with(Mockery::on(function (string $value) use (&$command): bool {
            $command = $value;

            return true;
        }))->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->with(Mockery::on(fn (Server $value): bool => $value->is($server)))->andReturnSelf();
        $runner->shouldReceive('create')->once()->with(false)->andReturn($ssh);

        return $runner;
    }
}
