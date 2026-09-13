<?php

namespace Tests\Feature;

use App\Data\BackupRecoverySummary;
use App\Models\BackupDestination;
use App\Models\BackupRestore;
use App\Models\BackupRestoreVerification;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Services\BackupRecoveryEvidenceQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupRecoveryEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_summary_separates_completion_transport_restore_and_independent_verification(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$owner, $website] = $this->infrastructure();
        $destination = $this->destination($owner);

        $oldSuccessfulBackup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'aaaaaaaaaaaaaaaa',
            'completed_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);
        $latestCompletedBackup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'bbbbbbbbbbbbbbbb',
            'completed_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $transportBackup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'cccccccccccccccc',
            'https_verified_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        for ($index = 0; $index < 51; $index++) {
            $website->backups()->create([
                'backup_destination_id' => $destination->id,
                'status' => WebsiteBackup::STATUS_FAILED,
                'error' => 'Remote backup failed.',
                'created_at' => now()->subMinutes(50 - $index),
                'updated_at' => now()->subMinutes(50 - $index),
            ]);
        }

        $restore = $transportBackup->restores()->create([
            'requested_by' => $owner->id,
            'status' => BackupRestore::STATUS_SUCCEEDED,
            'started_at' => now()->subMinutes(45),
            'completed_at' => now()->subMinutes(30),
        ]);

        $summary = app(BackupRecoveryEvidenceQuery::class)->summary($owner->currentOrganization);

        $this->assertInstanceOf(BackupRecoverySummary::class, $summary);
        $this->assertSame($latestCompletedBackup->completed_at->toDateTimeString(), $summary->latestBackupCompletedAt?->toDateTimeString());
        $this->assertSame($transportBackup->https_verified_at->toDateTimeString(), $summary->latestTransportVerifiedAt?->toDateTimeString());
        $this->assertSame($restore->completed_at->toDateTimeString(), $summary->latestRestoreCompletedAt?->toDateTimeString());
        $this->assertSame(900, $summary->latestRestoreSeconds);
        $this->assertNull($summary->latestIndependentRecoveryVerificationAt);
        $this->assertNotSame($oldSuccessfulBackup->completed_at->toDateTimeString(), $summary->latestBackupCompletedAt?->toDateTimeString());

        $response = $this->actingAs($owner)->get(route('backups.index'));
        $response->assertOk()
            ->assertSee('Latest completed backup')
            ->assertSee('Latest HTTPS transport evidence')
            ->assertSee('Latest in-place restore')
            ->assertSee('Independent restore verification')
            ->assertSee('Not recorded');
        $response->assertViewHas('recoverySummary', fn (BackupRecoverySummary $viewSummary): bool => $viewSummary->latestRestoreSeconds === 900
            && $viewSummary->latestIndependentRecoveryVerificationAt === null);
    }

    public function test_summary_excludes_foreign_workspace_backup_and_restore_evidence(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$owner, $website] = $this->infrastructure();
        $destination = $this->destination($owner);
        $backup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'dddddddddddddddd',
            'completed_at' => now()->subHour(),
        ]);

        $foreignOwner = User::factory()->create();
        [, $foreignWebsite] = $this->infrastructure($foreignOwner);
        $foreignDestination = $this->destination($foreignOwner);
        $foreignBackup = $foreignWebsite->backups()->create([
            'backup_destination_id' => $foreignDestination->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'snapshot_id' => 'eeeeeeeeeeeeeeee',
            'https_verified_at' => now(),
            'completed_at' => now(),
        ]);
        $foreignBackup->restores()->create([
            'requested_by' => $foreignOwner->id,
            'status' => BackupRestore::STATUS_SUCCEEDED,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
        $foreignBackup->verifications()->create([
            'requested_by' => $foreignOwner->id,
            'snapshot_id' => $foreignBackup->snapshot_id,
            'target_type' => BackupRestoreVerification::TARGET_SAME_SERVER_TEMPORARY,
            'overwrite_mode' => BackupRestoreVerification::OVERWRITE_NEVER,
            'status' => BackupRestoreVerification::STATUS_SUCCEEDED,
            'integrity_status' => BackupRestoreVerification::CHECK_PASSED,
            'smoke_status' => BackupRestoreVerification::CHECK_PASSED,
            'cleanup_status' => BackupRestoreVerification::CLEANUP_PASSED,
            'completed_at' => now(),
        ]);

        $summary = app(BackupRecoveryEvidenceQuery::class)->summary($owner->currentOrganization);

        $this->assertSame($backup->completed_at->toDateTimeString(), $summary->latestBackupCompletedAt?->toDateTimeString());
        $this->assertNull($summary->latestTransportVerifiedAt);
        $this->assertNull($summary->latestRestoreCompletedAt);
        $this->assertNull($summary->latestRestoreSeconds);
        $this->assertNull($summary->latestIndependentRecoveryVerificationAt);
    }

    /** @return array{User, Website} */
    private function infrastructure(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
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
            'created_by' => $owner->id,
            'name' => 'R2', 'endpoint' => 'https://storage.example.com', 'bucket' => 'buildpusher-backups',
            'region' => 'auto', 'access_key' => 'access-key', 'secret_key' => 'secret-key',
            'repository_password' => 'repository-secret', 'path_prefix' => 'production',
        ]);
    }
}
