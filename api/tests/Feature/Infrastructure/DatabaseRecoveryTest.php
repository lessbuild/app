<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\ServerType;
use App\Models\BackupDestination;
use App\Models\DatabaseBackupPlan;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Services\Infrastructure\DatabaseRecoveryScripts;
use App\Services\Infrastructure\Scripts\Database\InstallMysqlScript;
use App\Services\Infrastructure\Scripts\Database\InstallPostgresScript;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DatabaseRecoveryTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that database servers install the engine they were created with.
     *
     * @return void
     */
    public function test_database_servers_install_mysql_or_postgres(): void
    {
        $plan = app(ServerProvisioningPlan::class);
        $postgres = Server::factory()->make(['type' => ServerType::Database, 'database_engine' => 'postgres']);
        $mysql = Server::factory()->make(['type' => ServerType::Database, 'database_engine' => 'mysql']);

        $this->assertContains(InstallPostgresScript::class, $plan->steps($postgres));
        $this->assertNotContains(InstallMysqlScript::class, $plan->steps($postgres));
        $this->assertContains(InstallMysqlScript::class, $plan->steps($mysql));
        $script = (new InstallPostgresScript)->script(5, $postgres);
        $this->assertStringContainsString("listen_addresses = 'localhost'", $script);
        $this->assertStringContainsString("psql -v ON_ERROR_STOP=1 -c 'ALTER USER postgres WITH PASSWORD '\\''mysql-secret'\\'';'", $script);
    }

    /**
     * Check that continuous backup is set up on a PostgreSQL server as a server command, and that a restore needs the
     * name typed and a moment inside the window.
     *
     * @return void
     */
    public function test_a_postgres_server_is_backed_up_continuously_and_restored_to_a_moment(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id, 'type' => ServerType::Database, 'database_engine' => 'postgres', 'name' => 'db-1']);
        $destination = BackupDestination::factory()->create(['account_id' => $project->account_id, 'name' => 'Spaces']);
        $base = "/projects/{$project->id}/infrastructure/servers/{$server->id}";

        $this->actingAs($owner)->get("{$base}?tab=recovery")->assertOk()->assertSee('Turn on continuous backup');
        $this->actingAs($owner)->post("{$base}/database-recovery", ['backup_destination_id' => $destination->id, 'retention_days' => 40])->assertSessionHasErrors('retention_days');
        $this->actingAs($owner)->post("{$base}/database-recovery", ['backup_destination_id' => $destination->id, 'retention_days' => 7])->assertRedirect("{$base}?tab=recovery");
        $setup = ServerCommandExecution::query()->sole();
        $this->assertStringContainsString('wal-g-pg-ubuntu-22.04-amd64', $setup->command);
        $this->assertStringContainsString("WALG_S3_PREFIX='s3://shop-backups/buildpusher/database-recovery/server-{$server->id}'", $setup->command);
        $this->assertStringContainsString("archive_command = '/usr/local/bin/walg wal-push %p'", $setup->command);
        $this->assertStringContainsString('delete retain FULL 7 --confirm', $setup->command);
        $this->assertSame([$destination->id, 7], [DatabaseBackupPlan::query()->sole()->backup_destination_id, DatabaseBackupPlan::query()->sole()->retention_days]);
        $setup->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();

        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));
        $this->actingAs($owner)->get("{$base}?tab=recovery")->assertOk()->assertSee('Restorable from 2026-10-10 12:00 UTC');
        $restore = "{$base}/database-recovery/restore";
        $this->actingAs($owner)->post($restore, ['restore_to' => '2026-10-11T09:30:00', 'confirmation' => 'db-2'])->assertSessionHasErrors('confirmation');
        $this->actingAs($owner)->post($restore, ['restore_to' => '2026-10-09T09:30:00', 'confirmation' => 'db-1'])->assertSessionHasErrors('restore_to');
        $this->actingAs($owner)->post($restore, ['restore_to' => '2026-10-11T09:30:00', 'confirmation' => 'db-1'])->assertRedirect("{$base}?tab=recovery");
        $run = ServerCommandExecution::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString("recovery_target_time = '2026-10-11 09:30:00+00'", $run->command);
        $this->assertStringContainsString('walg backup-fetch "$DATA" LATEST', $run->command);
        $this->assertStringContainsString('before-restore-20261011093000', $run->command);
    }

    /**
     * Check the MySQL setup turns on the binary log and ships it, and the restore replays it to the moment.
     *
     * @return void
     */
    public function test_mysql_recovery_ships_and_replays_the_binary_log(): void
    {
        $server = Server::factory()->make(['id' => 9, 'type' => ServerType::Database, 'database_engine' => 'mysql']);
        $destination = BackupDestination::factory()->make(['path_prefix' => '']);
        $scripts = app(DatabaseRecoveryScripts::class);

        $setup = $scripts->enable($server, $destination, 3);
        $this->assertStringContainsString("sed -i '/^skip-log-bin/d'", $setup);
        $this->assertStringContainsString('*/5 * * * * root /usr/local/bin/walg binlog-push', $setup);
        $this->assertStringContainsString("WALG_S3_PREFIX='s3://shop-backups/database-recovery/server-9'", $setup);
        $this->assertStringContainsString('binlog-replay --since LATEST --until "2026-10-11T09:30:00Z"', $scripts->restore($server, CarbonImmutable::parse('2026-10-11 09:30:00', 'UTC')));
    }
}
