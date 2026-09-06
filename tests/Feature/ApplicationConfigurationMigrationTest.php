<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationOwnership;
use App\Models\ConfigurationReview;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ApplicationConfigurationMigrationTest extends TestCase
{
    // Deliberately no RefreshDatabase: migration rollback must run outside an
    // enclosing test transaction against a disposable, explicitly named database.
    private const CONNECTION = 'configuration_migration_test';

    private const MIGRATIONS = [
        '2026_09_06_010000_create_configuration_reviews.php',
        '2026_09_06_020000_create_configuration_ownerships.php',
        '2026_09_06_030000_create_configuration_applications.php',
        '2026_09_06_040000_create_configuration_operations.php',
        '2026_09_06_050000_link_configuration_operation_receipts.php',
        '2026_09_06_060000_add_configuration_operation_retries.php',
    ];

    public function test_configuration_migrations_preserve_populated_application_data_through_rollout_rollback_and_reapply(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $originalConnection = DB::getDefaultConnection();
        $database = tempnam(sys_get_temp_dir(), 'buildpusher-configuration-migration-');
        $this->assertIsString($database);
        config(['database.connections.'.self::CONNECTION => array_replace(config('database.connections.sqlite'), [
            'database' => $database, 'url' => null, 'foreign_key_constraints' => true,
        ])]);
        DB::setDefaultConnection(self::CONNECTION);
        Queue::fake();
        Http::fake();

        try {
            $this->assertSame($database, DB::connection()->getDatabaseName());
            $this->assertSame(0, DB::transactionLevel());
            $paths = array_map(fn ($name) => database_path('migrations/'.$name), self::MIGRATIONS);
            $baselinePaths = array_values(array_diff(glob(database_path('migrations/*.php')), $paths));
            $this->migrate($baselinePaths);
            foreach ($this->configurationTables() as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $fixture = $this->baselineFixture();
            $before = $this->baselineSnapshot();

            $this->migrate(array_slice($paths, 0, 5));
            $existingOperation = $this->existingConfigurationOperation($fixture);
            $operationBefore = $existingOperation->fresh()->getRawOriginal();
            $this->migrate([$paths[5]]);
            $this->assertSame($operationBefore, array_intersect_key($existingOperation->fresh()->getRawOriginal(), $operationBefore));
            $this->assertSame(0, $existingOperation->fresh()->retry_sequence);
            $this->assertNull($existingOperation->fresh()->retry_of_operation_id);
            $this->assertSame($before, $this->baselineSnapshot());
            $this->assertConfigurationSchema();
            $this->assertSame(6, DB::table('migrations')->where('batch', '>', 1)->count());

            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--database' => self::CONNECTION, '--step' => 6, '--force' => true,
            ]), Artisan::output());
            foreach ($this->configurationTables() as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertSame($before, $this->baselineSnapshot());
            $this->assertSame(0, DB::table('migrations')->where('batch', '>', 1)->count());

            $this->migrate($paths);
            $this->assertConfigurationSchema();
            $this->assertSame($before, $this->baselineSnapshot());
            $this->assertSchemaConstraints($fixture);
            $this->assertSame($before, $this->baselineSnapshot());
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        } finally {
            DB::disconnect(self::CONNECTION);
            DB::purge(self::CONNECTION);
            DB::setDefaultConnection($originalConnection);
            foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $this->assertFileDoesNotExist($database);
        $this->assertSame($originalConnection, DB::getDefaultConnection());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    private function migrate(array $paths): void
    {
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => self::CONNECTION, '--path' => $paths, '--realpath' => true, '--force' => true,
        ]), Artisan::output());
    }

    private function assertConfigurationSchema(): void
    {
        foreach ($this->configurationTables() as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        foreach (['intent_digest', 'retry_sequence', 'retry_of_operation_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('configuration_operations', $column));
        }
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    private function assertSchemaConstraints(array $fixture): void
    {
        $review = ConfigurationReview::create([
            'project_id' => $fixture['project']->id, 'requested_by' => $fixture['user']->id,
            'document' => 'version: 2', 'bindings' => [], 'summary' => [], 'expires_at' => now()->addMinutes(15),
        ]);
        $ownership = ConfigurationOwnership::create([
            'project_id' => $fixture['project']->id, 'configuration_review_id' => $review->id,
            'environment_slug' => 'staging', 'kind' => 'environment', 'logical_name' => 'staging',
            'resource_id' => $fixture['environment']->id,
        ]);
        $ownerAttributes = $ownership->only(['project_id', 'configuration_review_id', 'environment_slug', 'kind', 'logical_name', 'resource_id']);
        $this->assertConstraint(fn () => ConfigurationOwnership::create(array_replace($ownerAttributes, ['resource_id' => 999999])));
        $this->assertConstraint(fn () => ConfigurationOwnership::create(array_replace($ownerAttributes, ['logical_name' => 'another-name'])));

        $application = ConfigurationApplication::create(['configuration_review_id' => $review->id, 'status' => 'locally_applied']);
        $this->assertConstraint(fn () => ConfigurationApplication::create(['configuration_review_id' => $review->id, 'status' => 'locally_applied']));
        $operationAttributes = [
            'configuration_application_id' => $application->id, 'environment_slug' => 'staging',
            'environment_id' => $fixture['environment']->id, 'build_id' => $fixture['build']->id,
            'kind' => 'deploy', 'status' => 'failed', 'payload' => ['retained' => 'private-operation-snapshot'],
        ];
        $operation = ConfigurationOperation::create($operationAttributes);
        $this->assertSame(0, $operation->fresh()->retry_sequence);
        $this->assertConstraint(fn () => ConfigurationOperation::create(array_replace($operationAttributes, ['build_id' => null])));
        $this->assertConstraint(fn () => ConfigurationOperation::create(array_replace($operationAttributes, ['environment_slug' => 'another-name'])));
        $retry = ConfigurationOperation::create(array_replace($operationAttributes, [
            'build_id' => null, 'retry_sequence' => 1, 'retry_of_operation_id' => $operation->id,
        ]));
        $this->assertConstraint(fn () => ConfigurationOperation::create(array_replace($operationAttributes, [
            'build_id' => null, 'retry_sequence' => 2, 'retry_of_operation_id' => $operation->id,
        ])));
        $this->assertConstraint(fn () => ConfigurationOperation::create(array_replace($operationAttributes, [
            'build_id' => null, 'retry_sequence' => 3, 'retry_of_operation_id' => 999999,
        ])));
        $receipt = ['configuration_application_id' => $application->id, 'configuration_operation_id' => $operation->id];
        DB::table('configuration_operation_receipts')->insert($receipt);
        $this->assertConstraint(fn () => DB::table('configuration_operation_receipts')->insert($receipt));

        // A deployment retry creates a second identity. Its migration must refuse
        // rollback instead of dropping history or restoring an incompatible key.
        $migration = require database_path('migrations/'.self::MIGRATIONS[5]);
        try {
            $migration->down();
            $this->fail('Retry history was silently discarded by migration rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Configuration retry history must be archived before rolling back this migration.', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('configuration_operations', 'retry_of_operation_id'));
        $this->assertSame($operation->id, $retry->fresh()->retry_of_operation_id);
        $this->assertSame('private-operation-snapshot', $operation->fresh()->payload['retained']);
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        $review->delete();
        $this->assertNull($ownership->fresh()->configuration_review_id);
        $this->assertDatabaseCount('configuration_applications', 0, self::CONNECTION);
        $this->assertDatabaseCount('configuration_operations', 0, self::CONNECTION);
        $this->assertDatabaseCount('configuration_operation_receipts', 0, self::CONNECTION);
        $this->assertNotNull($fixture['build']->fresh());
    }

    private function existingConfigurationOperation(array $fixture): ConfigurationOperation
    {
        $review = ConfigurationReview::create([
            'project_id' => $fixture['project']->id, 'requested_by' => $fixture['user']->id,
            'document' => 'version: 2', 'bindings' => [], 'summary' => [], 'expires_at' => now()->addMinutes(15),
        ]);
        $application = ConfigurationApplication::create(['configuration_review_id' => $review->id, 'status' => 'remote_failed']);

        return ConfigurationOperation::create([
            'configuration_application_id' => $application->id, 'environment_slug' => 'staging',
            'environment_id' => $fixture['environment']->id, 'build_id' => $fixture['build']->id,
            'kind' => 'deploy', 'status' => 'failed', 'payload' => ['retained' => 'private-operation-snapshot'],
            'intent_digest' => str_repeat('a', 64),
        ]);
    }

    private function assertConstraint(callable $insert): void
    {
        try {
            $insert();
            $this->fail('A duplicate configuration identity or invalid foreign key was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
    }

    private function baselineFixture(): array
    {
        $user = User::factory()->create();
        $project = $user->currentOrganization->projects()->create(['name' => 'Existing app', 'slug' => 'existing', 'created_by' => $user->id]);
        $server = $user->servers()->create(['name' => 'Existing server']);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'Existing site', 'url' => 'existing.example', 'description' => 'Test', 'environment' => '']);
        $provider = $user->providers()->create(['name' => 'GitHub', 'provider' => 'github', 'token' => 'private-token', 'description' => 'Test']);
        $repository = $user->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Existing app', 'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test']);
        $environment = $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'website_id' => $website->id, 'server_id' => $server->id]);
        $environment->processes()->create(['name' => 'worker', 'type' => 'worker', 'command' => 'private-worker-command', 'replicas' => 2]);
        $environment->resources()->create(['name' => 'cache', 'type' => 'redis', 'is_managed' => true, 'configuration' => ['variables' => ['REDIS_HOST' => '127.0.0.1']]]);
        $variable = $environment->variables()->create(['key' => 'TOKEN', 'value' => 'private-secret', 'updated_by' => $user->id, 'is_secret' => true]);
        $variable->versions()->create(['version' => 1, 'value' => 'private-secret', 'created_by' => $user->id]);
        $build = $repository->builds()->create(['environment_id' => $environment->id, 'status' => Build::STATUS_SUCCEEDED, 'environment_payload' => ['variables' => ['TOKEN' => 'historical-secret']]]);

        return compact('user', 'project', 'environment', 'build');
    }

    private function baselineSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'users', 'organizations', 'organization_user', 'projects', 'providers', 'servers', 'websites', 'repositories',
            'environments', 'environment_processes', 'environment_resources', 'environment_variables', 'environment_variable_versions', 'builds',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function configurationTables(): array
    {
        return [
            'configuration_reviews', 'configuration_ownerships', 'configuration_applications',
            'configuration_operations', 'configuration_operation_receipts',
        ];
    }
}
