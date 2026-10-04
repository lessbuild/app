<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Actions\Infrastructure\CheckReadReplicas;
use App\Enums\AccountRole;
use App\Enums\ServerType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Notifications\ReplicationBrokenNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ReadReplicasTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that a MySQL server gets a replica over the private network: the primary's login and firewall, the
     * replica's copy with the primary's application logins, the check that marks it streaming and then broken (telling
     * the owners once), and promotion.
     *
     * @return void
     */
    public function test_a_mysql_server_gets_a_read_replica_that_is_checked_and_promoted(): void
    {
        $this->fakeInfrastructure();
        Notification::fake();
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $primary = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::Database, 'database_engine' => 'mysql', 'name' => 'db-1', 'private_ip' => '10.0.0.1']);
        $replica = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::Database, 'database_engine' => 'mysql', 'name' => 'db-2', 'private_ip' => '10.0.0.2']);
        $postgres = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::Database, 'database_engine' => 'postgres', 'name' => 'pg-1']);
        $base = "/api/app/projects/{$project->id}/infrastructure/servers/{$primary->id}";

        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('replication.candidates', fn (array $candidates): bool => count($candidates) === 1 && str_starts_with($candidates[0]['label'], 'db-2'));
        $this->actingAs($owner)->postJson("{$base}/replicas", ['replica_server_id' => $postgres->id, 'confirmation' => 'pg-1'])->assertJsonValidationErrors('replica_server_id');
        $this->actingAs($owner)->postJson("{$base}/replicas", ['replica_server_id' => $replica->id, 'confirmation' => 'db-3'])->assertJsonValidationErrors('confirmation');

        $this->shell->reply("Created\nlogins=".base64_encode("CREATE USER 'shop'@'%';")."\n");
        $this->actingAs($owner)->postJson("{$base}/replicas", ['replica_server_id' => $replica->id, 'confirmation' => 'db-2'])->assertJsonRedirect("{$base}?tab=replicas");

        $prepare = $this->shell->ran[0];
        $this->assertSame($primary->id, $prepare['server']);
        $this->assertStringContainsString("CREATE USER IF NOT EXISTS 'lessbuild_replica_{$replica->id}'@'10.0.0.2'", $prepare['command']);
        $this->assertStringContainsString("ufw allow from '10.0.0.2' to any port 3306", $prepare['command']);
        $this->assertStringContainsString('gtid_mode = ON', $prepare['command']);
        $copy = $this->scripts->started[0];
        $this->assertSame($replica->id, $copy['server']);
        $this->assertStringContainsString("SOURCE_HOST = '10.0.0.1'", $copy['script']);
        $this->assertStringContainsString('server_id = '.(1000 + $replica->id), $copy['script']);
        $this->assertStringContainsString(base64_encode("CREATE USER 'shop'@'%';"), $copy['script']);
        $this->assertStringContainsString('echo done > /root/.lessbuild-replica-setup', $copy['script']);
        $replica->refresh();
        $this->assertSame([$primary->id, 'setting_up'], [$replica->replica_of_server_id, $replica->replication_status]);
        $this->assertSame(40, strlen((string) $replica->replication_password));

        $this->shell->reply('running');
        app(CheckReadReplicas::class)->handle();
        $this->assertSame('setting_up', $replica->refresh()->replication_status);

        $this->shell->reply('done')->reply("running=yes\nlag=3\n");
        app(CheckReadReplicas::class)->handle();
        $this->assertSame(['streaming', 3], [$replica->refresh()->replication_status, $replica->replication_lag_seconds]);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('replication.replicas.0.status', 'streaming')->assertJsonPath('replication.replicas.0.lagSeconds', 3)->assertJsonPath('replication.replicas.0.address', '10.0.0.2');
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/infrastructure/servers/{$replica->id}")->assertOk()->assertJsonPath('replication.primary.name', 'db-1')->assertJsonPath('canRunCommands', true);

        $this->shell->reply("running=no\nlag=\nerror=Error connecting to source\n");
        app(CheckReadReplicas::class)->handle();
        $this->assertSame(['broken', 'Error connecting to source'], [$replica->refresh()->replication_status, $replica->replication_error]);
        Notification::assertSentTo($owner, ReplicationBrokenNotification::class);
        $this->shell->reply("running=no\nlag=\nerror=Error connecting to source\n");
        app(CheckReadReplicas::class)->handle();
        Notification::assertSentToTimes($owner, ReplicationBrokenNotification::class, 1);

        Queue::fake();
        $promote = "/api/app/projects/{$project->id}/infrastructure/servers/{$replica->id}/promote";
        $this->actingAs($owner)->postJson($promote, ['confirmation' => 'db-1'])->assertJsonValidationErrors('confirmation');
        $this->actingAs($owner)->postJson($promote, ['confirmation' => 'db-2'])->assertJsonRedirect("/api/app/projects/{$project->id}/infrastructure/servers/{$replica->id}?tab=replicas");
        $this->assertStringContainsString('STOP REPLICA; RESET REPLICA ALL; SET GLOBAL super_read_only = OFF', ServerCommandExecution::query()->sole()->command);
        $this->assertNull($replica->refresh()->replica_of_server_id);
        $this->assertNull($replica->replication_password);
    }

    /**
     * Check that a PostgreSQL replica in another region copies over TLS on public addresses, and that a failed copy is
     * shown and can be started again.
     *
     * @return void
     */
    public function test_a_postgres_replica_copies_over_tls_and_a_failed_copy_can_start_again(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $primary = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::Database, 'database_engine' => 'postgres', 'name' => 'pg-1', 'public_ip' => '203.0.113.10', 'private_ip' => '10.0.0.1']);
        $replica = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::Database, 'database_engine' => 'postgres', 'name' => 'pg-2', 'public_ip' => '203.0.113.20', 'private_ip' => '10.1.0.1', 'region' => 'ams3']);
        $base = "/api/app/projects/{$project->id}/infrastructure/servers/{$primary->id}";

        $this->actingAs($owner)->postJson("{$base}/replicas", ['replica_server_id' => $replica->id, 'confirmation' => 'pg-2'])->assertSuccessful();

        $prepare = $this->shell->ran[0]['command'];
        $this->assertStringContainsString("hostssl replication lessbuild_replica_{$replica->id} 203.0.113.20/32 scram-sha-256", $prepare);
        $this->assertStringContainsString('\\gexec', $prepare);
        $copy = $this->scripts->started[0]['script'];
        $this->assertStringContainsString("pg_basebackup -d 'host=203.0.113.10 port=5432 user=lessbuild_replica_{$replica->id} sslmode=require'", $copy);
        $this->assertStringContainsString('.before-replica-', $copy);

        $this->shell->reply("failed\npg_basebackup: error: connection refused");
        app(CheckReadReplicas::class)->handle();
        $this->assertSame(['failed', 'pg_basebackup: error: connection refused'], [$replica->refresh()->replication_status, $replica->replication_error]);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('replication.replicas.0.status', 'failed')->assertSee('connection refused');

        $this->actingAs($owner)->postJson("{$base}/replicas", ['replica_server_id' => $replica->id, 'confirmation' => 'pg-2'])->assertSuccessful();
        $this->assertSame('setting_up', $replica->refresh()->replication_status);
        $this->assertCount(2, $this->scripts->started);
    }

    /**
     * Check that only people who may run commands on both servers can add a replica, and replicas can't be chained.
     *
     * @return void
     */
    public function test_replicas_need_command_access_and_cannot_be_chained(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['infrastructure'])->create();
        $owner = $this->ownerOf($project);
        $provider = Provider::factory()->create(['account_id' => $project->account_id]);
        $primary = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::Database, 'database_engine' => 'mysql', 'name' => 'db-1']);
        $replica = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::Database, 'database_engine' => 'mysql', 'name' => 'db-2', 'replica_of_server_id' => $primary->id, 'replication_status' => 'streaming']);
        $third = Server::factory()->create(['provider_id' => $provider->id, 'type' => ServerType::Database, 'database_engine' => 'mysql', 'name' => 'db-3']);
        $other = Server::factory()->create(['type' => ServerType::Database, 'database_engine' => 'mysql', 'name' => 'elsewhere']);

        $this->actingAs($owner)->postJson("/api/app/projects/{$project->id}/infrastructure/servers/{$replica->id}/replicas", ['replica_server_id' => $third->id, 'confirmation' => 'db-3'])->assertJsonValidationErrors('replica_server_id');
        $this->actingAs($owner)->postJson("/api/app/projects/{$project->id}/infrastructure/servers/{$third->id}/replicas", ['replica_server_id' => $primary->id, 'confirmation' => 'db-1'])->assertJsonValidationErrors('replica_server_id');
        $this->actingAs($owner)->postJson("/api/app/projects/{$project->id}/infrastructure/servers/{$primary->id}/replicas", ['replica_server_id' => $other->id, 'confirmation' => 'elsewhere'])->assertNotFound();

        $viewer = User::factory()->create();
        $this->addMember($project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->postJson("/api/app/projects/{$project->id}/infrastructure/servers/{$primary->id}/replicas", ['replica_server_id' => $third->id, 'confirmation' => 'db-3'])->assertForbidden();
        $this->assertEmpty($this->shell->ran);
    }
}
