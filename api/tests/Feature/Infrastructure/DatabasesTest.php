<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Models\DatabaseClone;
use App\Models\DatabaseSnapshot;
use App\Models\DatabaseUser;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DatabasesTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

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
        $this->website = Website::factory()->create(['server_id' => $server->id, 'name' => 'Shop', 'deployment_slug' => 'shop-live']);
        $this->base = "/api/app/projects/{$this->project->id}/infrastructure/websites/{$this->website->id}";
        $this->withoutMiddleware(RequirePassword::class);
    }

    /**
     * Inspecting records size connections and tables.
     */
    public function test_inspecting_records_size_connections_and_tables(): void
    {
        $this->shell->reply("size_bytes=5242880\nactive_connections=3\ntable=migrations\ntable=orders\ntable=users\n");
        $this->actingAs($this->owner)->postJson("{$this->base}/database/inspect")->assertSuccessful();

        $snapshot = DatabaseSnapshot::query()->sole();
        $this->assertSame(['ready', 5242880, 3, ['migrations', 'orders', 'users'], $this->owner->id], [$snapshot->status, $snapshot->size_bytes, $snapshot->active_connections, $snapshot->tables, $snapshot->requested_by]);
        $command = $this->shell->ran[0]['command'];
        $this->assertStringContainsString("MYSQL_PWD='mysql-secret' mysql --protocol=socket -u root", $command);
        $this->assertStringContainsString("table_schema = 'shop_live'", str_replace("'\\''", "'", $command));
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('inspection.sizeBytes', 5 * 1024 * 1024)->assertJsonPath('inspection.tables', fn (array $tables): bool => in_array('orders', $tables, true) && in_array('users', $tables, true));

        $this->shell->reply('', 1, 'Access denied');
        $this->command('databases:inspect')->expectsOutput('Queued 1 database inspections.');
        $this->assertSame(['failed', null], [DatabaseSnapshot::query()->latest('id')->firstOrFail()->status, DatabaseSnapshot::query()->latest('id')->firstOrFail()->requested_by]);

        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, AccountRole::Viewer);
        $this->actingAs($viewer)->getJson($this->base)->assertOk()->assertJsonPath('inspection.error', 'The database couldn’t be inspected.')->assertJsonPath('canManageDatabase', false);
        $this->actingAs($viewer)->postJson("{$this->base}/database/inspect")->assertForbidden();
    }

    /**
     * Check the inspection reads the week's slow queries and the server's counters, shows the slow queries and tuning
     * suggestions, and offers to turn the slow query log on when it's off.
     *
     * @return void
     */
    public function test_slow_queries_and_tuning_suggestions_are_shown(): void
    {
        $this->shell->reply("size_bytes=5242880\nactive_connections=3\ntable=orders\nslow_log=0,FILE\nvar=innodb_buffer_pool_size=134217728\nvar=max_connections=151\nvar=innodb_data_bytes=1073741824\nCreated_tmp_tables\t5000\nCreated_tmp_disk_tables\t2500\nMax_used_connections\t150\nSlow_queries\t42\nUptime\t7200\n");
        $this->actingAs($this->owner)->postJson("{$this->base}/database/inspect")->assertSuccessful();
        $snapshot = DatabaseSnapshot::query()->sole();
        $this->assertFalse($snapshot->slow_log_enabled);
        $this->assertSame([134217728, 1073741824, 2500], [$snapshot->server_status['innodb_buffer_pool_size'] ?? null, $snapshot->server_status['innodb_data_bytes'] ?? null, $snapshot->server_status['created_tmp_disk_tables'] ?? null]);
        $this->assertStringContainsString('mysql.slow_log', str_replace("'\\''", "'", $this->shell->ran[0]['command']));
        $this->actingAs($this->owner)->getJson("{$this->base}?tab=database")->assertOk()
            ->assertJsonPath('inspection.tuning', fn (array $tuning): bool => str_contains(implode(' ', $tuning), 'smaller than your data') && str_contains(implode(' ', $tuning), '50% of temporary tables') && str_contains(implode(' ', $tuning), 'Connections peaked at 150'))->assertJsonPath('inspection.slowLogEnabled', false);

        $this->shell->reply('');
        $this->shell->reply("size_bytes=5242880\nslow_log=1,TABLE\nslow=12\t2.514\t4.02\t3400000\tSELECT * FROM orders WHERE customer_id = ?\n");
        $this->actingAs($this->owner)->postJson("{$this->base}/database/slow-log")->assertSuccessful();
        $this->assertStringContainsString('slow_query_log = 1', str_replace("'\\''", "'", $this->shell->ran[1]['command']));
        $latest = DatabaseSnapshot::query()->latest('id')->firstOrFail();
        $this->assertSame([true, 12, 'SELECT * FROM orders WHERE customer_id = ?'], [$latest->slow_log_enabled, $latest->slow_queries[0]['count'] ?? null, $latest->slow_queries[0]['query'] ?? null]);
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('inspection.slowLogEnabled', true)->assertSee('customer_id = ?')->assertSee('2.514');
    }

    /**
     * Database users are created with a one time password and removed when they expire.
     */
    public function test_database_users_are_created_with_a_one_time_password_and_removed_when_they_expire(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/database/users", ['username' => 'shop_live', 'privilege' => 'read'])->assertJsonValidationErrors('username');
        $this->actingAs($this->owner)->postJson("{$this->base}/database/users", ['username' => 'bad-name', 'privilege' => 'read'])->assertJsonValidationErrors('username');

        $response = $this->actingAs($this->owner)->postJson("{$this->base}/database/users", ['username' => 'Reporting', 'privilege' => 'read', 'expires_in_days' => 7]);
        $user = DatabaseUser::query()->sole();
        $response->assertJsonPath('secrets', ['database_user' => $user->password, 'database_user_name' => 'reporting']);
        $this->assertSame(['reporting', 'active'], [$user->username, $user->status]);
        $this->assertTrue($user->expires_at?->isSameDay(now()->addDays(7)));
        $grant = $this->shell->ran[0]['command'];
        $this->assertStringContainsString('GRANT SELECT, SHOW VIEW ON `shop_live`.*', $grant);
        $this->actingAs($this->owner)->postJson("{$this->base}/database/users", ['username' => 'reporting', 'privilege' => 'admin'])->assertJsonValidationErrors('username');
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('databaseUsers.0.username', 'reporting')->assertJsonPath('databaseUsers.0.privilege', 'Read only');

        $this->travel(8)->days();
        $this->command('databases:expire-users')->expectsOutput('Removing 1 expired database users.');
        $this->assertModelMissing($user);
        $this->assertStringContainsString("DROP USER IF EXISTS 'reporting'@'localhost'", str_replace("'\\''", "'", (string) (collect($this->shell->ran)->last()['command'] ?? '')));

        // A server that refuses the change marks the user failed once the retries run out.
        $this->shell->reply('', 1, 'ERROR 1045');
        $this->actingAs($this->owner)->postJson("{$this->base}/database/users", ['username' => 'tool', 'privilege' => 'write']);
        $this->assertSame('failed', DatabaseUser::query()->where('username', 'tool')->value('status'));
    }

    /**
     * A database is copied into another website on the same server after confirmation.
     */
    public function test_a_database_is_copied_into_another_website_on_the_same_server_after_confirmation(): void
    {
        $staging = Website::factory()->create(['server_id' => $this->website->server_id, 'name' => 'Shop staging', 'deployment_slug' => 'shop-staging']);
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $live = Website::factory()->create(['server_id' => $this->website->server_id, 'name' => 'Live', 'environment_id' => $production->id]);
        $elsewhere = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => $this->website->server?->provider_id])->id]);

        $this->actingAs($this->owner)->postJson("{$this->base}/database/copy", ['target_website_id' => $staging->id, 'confirmation' => 'Shop'])->assertJsonValidationErrors('confirmation');
        $this->actingAs($this->owner)->postJson("{$this->base}/database/copy", ['target_website_id' => $live->id, 'confirmation' => 'Live'])->assertJsonValidationErrors('target_website_id');
        $this->actingAs($this->owner)->postJson("{$this->base}/database/copy", ['target_website_id' => $elsewhere->id, 'confirmation' => $elsewhere->name])->assertJsonValidationErrors('target_website_id');
        $this->actingAs($this->owner)->postJson("{$this->base}/database/copy", ['target_website_id' => Website::factory()->create()->id, 'confirmation' => 'x'])->assertNotFound();
        $this->assertSame(0, DatabaseClone::query()->count());

        $this->actingAs($this->owner)->postJson("{$this->base}/database/copy", ['target_website_id' => $staging->id, 'confirmation' => 'Shop staging'])->assertSuccessful();
        $this->assertSame('succeeded', DatabaseClone::query()->sole()->status);
        $command = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        $this->assertStringContainsString('--add-drop-table shop_live | MYSQL_PWD=', $command);
        $this->assertStringEndsWith('mysql --protocol=socket -u root shop_staging', $command);
        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('copies.0.source', 'Shop')->assertJsonPath('copies.0.target', 'Shop staging');
    }

    /**
     * Check an anonymised copy masks personal data by column name after the import: running the generated commands
     * (with a stand-in mysql that lists some columns) updates exactly the personal columns, with values derived from
     * the originals, and skips names that aren't plain identifiers.
     *
     * @return void
     */
    public function test_an_anonymised_copy_masks_personal_data(): void
    {
        $staging = Website::factory()->create(['server_id' => $this->website->server_id, 'name' => 'Shop staging', 'deployment_slug' => 'shop-staging']);
        $this->actingAs($this->owner)->postJson("{$this->base}/database/copy", ['target_website_id' => $staging->id, 'confirmation' => 'Shop staging', 'anonymise' => '1'])->assertSuccessful();
        $this->assertTrue(DatabaseClone::query()->sole()->anonymise);
        $command = (string) (collect($this->shell->ran)->last()['command'] ?? '');
        $this->assertStringContainsString('# Mask personal data', $command);
        $masking = substr($command, (int) strpos($command, '# Mask personal data'));

        // A stand-in mysql: information_schema queries return columns by pattern; UPDATEs are recorded.
        $stub = <<<'BASH'
        mysql() {
            local sql="${@: -1}"
            case "$sql" in
                *"email"*"SELECT"*|*"SELECT"*"email"*) printf 'users\temail\ncustomers\tbilling_email\n' ;;
                *"SELECT"*"first_name"*) printf 'users\tname\n' ;;
                *"SELECT"*"phone"*) printf 'bad-table;drop\tphone\n' ;;
                UPDATE*) echo "$sql" >> "$LOG" ;;
            esac
        }
        BASH;
        $log = tempnam(sys_get_temp_dir(), 'mask');
        exec('LOG='.escapeshellarg((string) $log).' bash -c '.escapeshellarg($stub."\n".$masking).' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $updates = (string) file_get_contents((string) $log);
        $this->assertStringContainsString("UPDATE `shop_staging`.`users` SET `email` = CONCAT('user-', SUBSTRING(MD5(`email`), 1, 12), '@example.test') WHERE `email` IS NOT NULL", $updates);
        $this->assertStringContainsString('UPDATE `shop_staging`.`customers` SET `billing_email`', $updates);
        $this->assertStringContainsString("UPDATE `shop_staging`.`users` SET `name` = CONCAT('Person '", $updates);
        $this->assertStringNotContainsString('bad-table', $updates, 'Names that aren’t plain identifiers are skipped.');
        @unlink((string) $log);
    }

    /**
     * Database tools need the plan but removing users does not.
     */
    public function test_database_tools_need_the_plan_but_removing_users_does_not(): void
    {
        $user = new DatabaseUser;
        $user->forceFill(['website_id' => $this->website->id, 'username' => 'old_tool', 'password' => 'x', 'privilege' => 'read', 'status' => 'active'])->save();
        $this->onTier($this->project, 'deploy', 'free');

        $this->actingAs($this->owner)->getJson($this->base)->assertJsonPath('canManageDatabase', false)->assertJsonPath('canManage', true);
        $this->actingAs($this->owner)->postJson("{$this->base}/database/inspect")->assertForbidden();
        $this->actingAs($this->owner)->postJson("{$this->base}/database/users", ['username' => 'tool', 'privilege' => 'read'])->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("{$this->base}/database/users/{$user->id}")->assertSuccessful();
        $this->assertModelMissing($user);
    }
}
