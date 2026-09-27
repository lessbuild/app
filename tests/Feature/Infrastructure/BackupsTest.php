<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Jobs\Infrastructure\CreateWebsiteBackup;
use App\Models\BackupDestination;
use App\Models\BackupRestore;
use App\Models\BackupVerification;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class BackupsTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private const SNAPSHOT_OUTPUT = "{\"message_type\":\"summary\",\"total_bytes_processed\":2048,\"snapshot_id\":\"ABCDEF0123456789\"}\n";

    private Project $project;

    private User $owner;

    private Website $website;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->onTier($this->project, 'deploy', 'pro');
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->website = Website::factory()->create(['server_id' => $server->id, 'name' => 'Shop']);
        $this->base = "/projects/{$this->project->id}/infrastructure";
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_destinations_are_added_checked_changed_and_deleted_by_admins(): void
    {
        $this->actingAs($this->owner)->post("{$this->base}/backups/destinations", [
            'name' => 'Spaces', 'storage_provider' => 'digitalocean_spaces', 'region' => 'ams3', 'bucket' => 'shop-backups',
            'path_prefix' => '/buildpusher/', 'access_key' => 'DO00KEY', 'secret_key' => 'top-secret',
        ])->assertRedirect("{$this->base}/backups")->assertSessionHasNoErrors();
        $destination = BackupDestination::query()->sole();
        $this->assertSame(['https://ams3.digitaloceanspaces.com', 'buildpusher', 'DO00KEY', 40], [$destination->endpoint, $destination->path_prefix, $destination->access_key, strlen($destination->repository_password)]);

        // A bucket that stores what's written, until access is revoked.
        $bucket = new class
        {
            public string $body = '';

            public bool $denied = false;
        };
        Http::fake(function (Request $request) use ($bucket) {
            if ($bucket->denied) {
                return Http::response('<Error><Code>AccessDenied</Code><Message>top-secret</Message></Error>', 403);
            }
            if ($request->method() === 'PUT') {
                $bucket->body = $request->body();
            }

            return Http::response($request->method() === 'GET' ? $bucket->body : '', 200);
        });
        $this->actingAs($this->owner)->post("{$this->base}/backups/destinations/{$destination->id}/check")->assertSessionHas('status', 'The destination works.');
        $this->assertNotNull($this->reload($destination)->last_verified_at);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_starts_with($request->url(), 'https://ams3.digitaloceanspaces.com/shop-backups/buildpusher/connection-tests/')
            && str_contains($request->header('Authorization')[0] ?? '', 'Credential=DO00KEY/') && str_contains($request->header('Authorization')[0] ?? '', '/ams3/s3/aws4_request'));

        $bucket->denied = true;
        $this->actingAs($this->owner)->post("{$this->base}/backups/destinations/{$destination->id}/check")->assertSessionHasErrors('destination');
        $this->assertSame('The write request failed (HTTP 403, AccessDenied).', $this->reload($destination)->last_error);

        // Blank keys keep the stored ones.
        $this->actingAs($this->owner)->put("{$this->base}/backups/destinations/{$destination->id}", [
            'name' => 'Spaces EU', 'storage_provider' => 'digitalocean_spaces', 'region' => 'ams3', 'bucket' => 'shop-backups', 'path_prefix' => 'buildpusher', 'access_key' => '', 'secret_key' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['Spaces EU', 'DO00KEY', 'top-secret'], [$this->reload($destination)->name, $this->reload($destination)->access_key, $this->reload($destination)->secret_key]);

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->get("{$this->base}/backups")->assertOk()->assertSee('Spaces EU')->assertDontSee('Add a destination')->assertDontSee('top-secret');
        $this->actingAs($viewer)->post("{$this->base}/backups/destinations/{$destination->id}/check")->assertForbidden();

        $foreign = BackupDestination::factory()->create();
        $this->actingAs($this->owner)->delete("{$this->base}/backups/destinations/{$foreign->id}")->assertNotFound();

        $this->backup(['backup_destination_id' => $destination->id]);
        $this->actingAs($this->owner)->put("{$this->base}/backups/destinations/{$destination->id}", [
            'name' => 'Spaces EU', 'storage_provider' => 'digitalocean_spaces', 'region' => 'ams3', 'bucket' => 'other-bucket', 'path_prefix' => 'buildpusher',
        ])->assertSessionHasErrors('bucket');
        $this->actingAs($this->owner)->delete("{$this->base}/backups/destinations/{$destination->id}")->assertSessionHasErrors('destination');
        WebsiteBackup::query()->delete();
        $this->actingAs($this->owner)->delete("{$this->base}/backups/destinations/{$destination->id}")->assertRedirect();
        $this->assertModelMissing($destination);
    }

    public function test_backing_up_now_runs_restic_on_the_server_and_records_the_snapshot(): void
    {
        $destination = BackupDestination::factory()->create(['account_id' => $this->project->account_id]);
        $this->shell->reply(self::SNAPSHOT_OUTPUT);

        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups", ['backup_destination_id' => $destination->id])->assertSessionHas('status', 'Backup started.');

        $backup = WebsiteBackup::query()->sole();
        $this->assertSame([WebsiteBackup::STATUS_SUCCEEDED, 'abcdef0123456789', 2048, $this->owner->id], [$backup->status, $backup->snapshot_id, $backup->size_bytes, $backup->triggered_by]);
        $script = $this->shell->ran[0]['command'];
        $this->assertStringContainsString("--databases '{$this->website->databaseIdentifier()}'", $script);
        $this->assertStringContainsString("RESTIC_REPOSITORY='s3:https://ams3.digitaloceanspaces.com/shop-backups/buildpusher/websites/{$this->website->id}'", $script);
        $this->assertStringContainsString("--keep-last 14 --tag 'website:{$this->website->id}'", $script);
        $this->assertNotNull($this->reload($destination)->last_verified_at);
        $this->actingAs($this->owner)->get("{$this->base}/websites/{$this->website->id}?tab=backups")->assertSee('Done')->assertSee('2 KB')->assertSee('Verify');
        $this->actingAs($this->owner)->get("{$this->base}/backups")->assertSee('Shop')->assertSee('2 KB')->assertSee('Last backup')->assertSee('ago');

        // One at a time per website.
        Queue::fake();
        $this->backup(['status' => WebsiteBackup::STATUS_RUNNING, 'backup_destination_id' => $destination->id]);
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups", ['backup_destination_id' => $destination->id])->assertSessionHas('status', 'A backup is already running for this website.');
        Queue::assertNothingPushed();
    }

    public function test_a_failed_backup_is_retried_then_recorded(): void
    {
        $backup = $this->backup(['status' => WebsiteBackup::STATUS_QUEUED]);
        $job = new CreateWebsiteBackup($backup->id);
        $this->shell->reply('', 1, 'Fatal: unable to open repository');
        try {
            app()->call([$job, 'handle']);
            $this->fail('The backup should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fatal: unable to open repository', $exception->getMessage());
        }
        $this->assertSame(WebsiteBackup::STATUS_QUEUED, $this->reload($backup)->status);
        $job->failed(new RuntimeException('Fatal: unable to open repository'));
        $this->assertSame([WebsiteBackup::STATUS_FAILED, 'Fatal: unable to open repository'], [$this->reload($backup)->status, $this->reload($backup)->error]);
    }

    public function test_schedules_queue_backups_when_due(): void
    {
        $destination = BackupDestination::factory()->create(['account_id' => $this->project->account_id]);
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backup-schedules", ['backup_destination_id' => $destination->id, 'frequency' => 'weekly', 'run_at' => '02:00', 'retention_count' => 7])->assertSessionHasErrors('weekday');
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backup-schedules", ['backup_destination_id' => BackupDestination::factory()->create()->id, 'frequency' => 'daily', 'run_at' => '02:00', 'retention_count' => 7])->assertNotFound();
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backup-schedules", ['backup_destination_id' => $destination->id, 'frequency' => 'daily', 'run_at' => '02:00', 'retention_count' => 7])->assertRedirect();
        $schedule = WebsiteBackupSchedule::query()->sole();
        $this->actingAs($this->owner)->get("{$this->base}/websites/{$this->website->id}?tab=backups")->assertSee('Every day at 02:00');

        Queue::fake();
        $this->travelTo(now('UTC')->setTime(1, 0));
        $this->command('backups:run')->expectsOutput('Queued 0 website backups.');
        $this->travelTo(now('UTC')->setTime(2, 5));
        $this->command('backups:run')->expectsOutput('Queued 1 website backups.');
        $this->command('backups:run')->expectsOutput('Queued 0 website backups.');
        Queue::assertPushed(CreateWebsiteBackup::class, 1);
        $this->assertSame($schedule->id, WebsiteBackup::query()->sole()->website_backup_schedule_id);

        // Not on plans without managed backups, where the controls are replaced by a note.
        WebsiteBackup::query()->delete();
        $this->travelTo(now('UTC')->addDay());
        $this->onTier($this->project, 'deploy', 'free');
        $this->command('backups:run')->expectsOutput('Queued 0 website backups.');
        $this->actingAs($this->owner)->get("{$this->base}/websites/{$this->website->id}?tab=backups")->assertSee('Managed backups come with the Pro Deploy plan')->assertDontSee('Back up now');
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups", ['backup_destination_id' => $destination->id])->assertForbidden();

        $this->actingAs($this->owner)->delete("{$this->base}/websites/{$this->website->id}/backup-schedules/{$schedule->id}")->assertRedirect();
        $this->assertModelMissing($schedule);
    }

    public function test_restores_run_with_a_safety_rollback_and_only_for_completed_backups(): void
    {
        $failed = $this->backup(['status' => WebsiteBackup::STATUS_FAILED]);
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups/{$failed->id}/restore")->assertSessionHasErrors('backup');

        $backup = $this->backup();
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $this->actingAs($member)->post("{$this->base}/websites/{$this->website->id}/backups/{$backup->id}/restore")->assertForbidden();

        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups/{$backup->id}/restore")->assertSessionHasNoErrors();
        $restore = BackupRestore::query()->sole();
        $this->assertSame('succeeded', $restore->status);
        $script = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        $this->assertStringContainsString("restic restore 'abcdef0123456789'", $script);
        $this->assertStringContainsString('trap rollback_restore ERR', $script);
        $this->assertStringContainsString('php artisan down --retry=30', $script);

        $this->shell->reply('', 1, 'restic: wrong password');
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups/{$backup->id}/restore");
        $this->assertSame(['failed', 'restic: wrong password'], [BackupRestore::query()->latest('id')->firstOrFail()->status, BackupRestore::query()->latest('id')->firstOrFail()->error]);

        // Restores still work after a downgrade.
        $this->onTier($this->project, 'deploy', 'free');
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups/{$backup->id}/restore")->assertSessionHasNoErrors();
        $this->assertSame(3, BackupRestore::query()->count());
    }

    public function test_verification_restores_into_a_temporary_database_and_reads_the_markers(): void
    {
        $backup = $this->backup();
        $this->shell->reply("BP_FAILURE_STAGE=preflight\nBP_FAILURE_STAGE=restore\nBP_FAILURE_STAGE=integrity\nBP_INTEGRITY_STATUS=passed\nBP_FAILURE_STAGE=smoke\nBP_SMOKE_STATUS=passed\nBP_CLEANUP_STATUS=passed\n");
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups/{$backup->id}/verify")->assertSessionHasNoErrors();
        $verification = BackupVerification::query()->sole();
        $this->assertSame(['succeeded', 'passed', 'passed', 'passed', null], [$verification->status, $verification->integrity_status, $verification->smoke_status, $verification->cleanup_status, $verification->failure_stage]);
        $script = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        $this->assertStringContainsString("TEMP_DATABASE='buildpusher_verify_{$verification->id}'", $script);
        $this->assertStringNotContainsString('php artisan down', $script);

        $this->shell->reply("BP_FAILURE_STAGE=preflight\nBP_FAILURE_STAGE=restore\nBP_FAILURE_STAGE=integrity\nBP_INTEGRITY_STATUS=passed\nBP_FAILURE_STAGE=smoke\nBP_CLEANUP_STATUS=passed\n", 1);
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups/{$backup->id}/verify");
        $failed = BackupVerification::query()->latest('id')->firstOrFail();
        $this->assertSame(['failed', 'passed', 'failed', 'passed', 'smoke'], [$failed->status, $failed->integrity_status, $failed->smoke_status, $failed->cleanup_status, $failed->failure_stage]);

        $this->shell->reply("BP_FAILURE_STAGE=preflight\n", 1);
        $this->actingAs($this->owner)->post("{$this->base}/websites/{$this->website->id}/backups/{$backup->id}/verify");
        $this->assertSame('cleanup', BackupVerification::query()->latest('id')->firstOrFail()->failure_stage);

        $this->actingAs($this->owner)->get("{$this->base}/websites/{$this->website->id}?tab=backups")->assertSee('The temporary directory or database couldn’t be removed.');
    }

    /** @param array<string, mixed> $attributes */
    private function backup(array $attributes = []): WebsiteBackup
    {
        $backup = new WebsiteBackup;
        $backup->forceFill([
            'website_id' => $this->website->id,
            'backup_destination_id' => $attributes['backup_destination_id'] ?? BackupDestination::factory()->create(['account_id' => $this->project->account_id])->id,
            'status' => WebsiteBackup::STATUS_SUCCEEDED, 'snapshot_id' => 'abcdef0123456789', 'completed_at' => now(), ...$attributes,
        ])->save();

        return $backup;
    }
}
