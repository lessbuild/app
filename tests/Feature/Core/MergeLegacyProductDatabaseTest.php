<?php

namespace Tests\Feature\Core;

use App\Core\Services\Migration\MergeLegacyProductDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SQLite3;
use Tests\TestCase;

final class MergeLegacyProductDatabaseTest extends TestCase
{
    private string $directory;

    private string $sourcePath;

    private string $targetPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/buildpusher-merge-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->sourcePath = $this->directory.'/source.sqlite';
        $this->targetPath = $this->directory.'/target.sqlite';
        $this->createDatabase($this->sourcePath);
        $this->createDatabase($this->targetPath);

        config([
            'database.connections.deployer.database' => $this->targetPath,
            'platform.migration_backup_directory' => $this->directory.'/backups',
        ]);
        DB::purge('deployer');
    }

    protected function tearDown(): void
    {
        DB::purge('deployer');
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_reports_a_safe_merge(): void
    {
        $this->seedSource();
        $this->seedBootstrapTarget();

        $report = app(MergeLegacyProductDatabase::class)->run('deployer', $this->sourcePath);

        $this->assertFalse($report['applied']);
        $this->assertSame(3, $report['rows_imported']);
        $this->assertSame(1, $report['rows_preserved']);
        $this->assertSame(1, $report['bootstrap_rows_relocated']);
        $this->assertSame(1, $report['account_provider_ids_restored']);
        $this->assertNull($report['backup_path']);
        $this->assertSame(1, DB::connection('deployer')->table('organizations')->count());
        $this->assertDatabaseMissing('organizations', ['slug' => 'legacy-org'], 'deployer');
        $this->assertNull(DB::connection('deployer')->table('users')->where('id', 1)->value('github_id'));
        $this->assertSame('App\\Models\\Build', $this->sourceEventType());
    }

    public function test_apply_preserves_bootstrap_rows_and_is_idempotent(): void
    {
        $this->seedSource();
        $this->seedBootstrapTarget();

        $service = app(MergeLegacyProductDatabase::class);
        $report = $service->run('deployer', $this->sourcePath, apply: true);

        $this->assertTrue($report['applied']);
        $this->assertSame(3, $report['rows_imported']);
        $this->assertSame(1, $report['rows_preserved']);
        $this->assertSame(1, $report['bootstrap_rows_relocated']);
        $this->assertSame(1, $report['account_provider_ids_restored']);
        $this->assertFileExists($report['backup_path']);
        $this->assertSame(0600, fileperms($report['backup_path']) & 0777);
        $this->assertDatabaseHas('organizations', ['id' => 1, 'slug' => 'legacy-org'], 'deployer');
        $this->assertDatabaseHas('organizations', ['id' => 2, 'slug' => 'bootstrap'], 'deployer');
        $this->assertDatabaseHas('projects', ['id' => 1, 'organization_id' => 2], 'deployer');
        $this->assertDatabaseHas('projects', ['id' => 2, 'organization_id' => 1], 'deployer');
        $this->assertDatabaseHas('events', [
            'id' => 2,
            'parentable_type' => 'App\\Modules\\Deployer\\Models\\Build',
        ], 'deployer');
        $this->assertDatabaseHas('users', [
            'id' => 1,
            'password' => 'target-password',
            'github_id' => 'github-user-1',
        ], 'deployer');
        $this->assertSame([], DB::connection('deployer')->select('PRAGMA foreign_key_check'));
        $this->assertSame('App\\Models\\Build', $this->sourceEventType());

        $retry = $service->run('deployer', $this->sourcePath, apply: true);

        $this->assertSame(0, $retry['rows_imported']);
        $this->assertSame(4, $retry['rows_preserved']);
        $this->assertSame(0, $retry['bootstrap_rows_relocated']);
        $this->assertSame(0, $retry['account_provider_ids_restored']);
        $this->assertSame(2, DB::connection('deployer')->table('organizations')->count());
    }

    public function test_conflicting_primary_keys_fail_without_partial_writes(): void
    {
        $this->seedSource();
        $this->seedBootstrapTarget();
        DB::connection('deployer')->table('projects')->insert([
            'id' => 2,
            'organization_id' => 1,
            'name' => 'different project',
        ]);

        try {
            app(MergeLegacyProductDatabase::class)->run('deployer', $this->sourcePath, apply: true);
            $this->fail('A conflicting primary key should stop the merge.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicting primary keys', $exception->getMessage());
        }

        $this->assertSame(1, DB::connection('deployer')->table('organizations')->count());
        $this->assertDatabaseMissing('organizations', ['slug' => 'legacy-org'], 'deployer');
        $this->assertDatabaseHas('projects', ['id' => 2, 'name' => 'different project'], 'deployer');
    }

    public function test_nonempty_serialized_queue_is_rejected(): void
    {
        $source = new SQLite3($this->sourcePath);
        $source->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
        $source->exec("INSERT INTO jobs (id, payload) VALUES (1, 'serialized-job')");
        $source->close();

        try {
            app(MergeLegacyProductDatabase::class)->run('deployer', $this->sourcePath);
            $this->fail('A non-empty queue should stop the merge preview.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contains serialized jobs', $exception->getMessage());
        }
    }

    public function test_provider_identity_conflict_fails_without_changing_the_matching_account(): void
    {
        $this->seedSource();
        $this->seedBootstrapTarget();
        DB::connection('deployer')->table('users')->insert([
            'id' => 2,
            'email' => 'other@example.test',
            'password' => 'other-password',
            'github_id' => 'github-user-1',
        ]);

        try {
            app(MergeLegacyProductDatabase::class)->run('deployer', $this->sourcePath, apply: true);
            $this->fail('A provider identity already owned by another account should stop the merge.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already assigned to another account', $exception->getMessage());
        }

        $this->assertNull(DB::connection('deployer')->table('users')->where('id', 1)->value('github_id'));
        $this->assertSame(1, DB::connection('deployer')->table('organizations')->count());
        $this->assertDatabaseMissing('organizations', ['slug' => 'legacy-org'], 'deployer');
    }

    private function createDatabase(string $path): void
    {
        $database = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('CREATE TABLE organizations (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE)');
        $database->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NOT NULL, name TEXT NOT NULL, FOREIGN KEY (organization_id) REFERENCES organizations(id))');
        $database->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, parentable_type TEXT NOT NULL, parentable_id INTEGER NOT NULL)');
        $database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, password TEXT NOT NULL, github_id TEXT UNIQUE, gitlab_id TEXT UNIQUE, bitbucket_id TEXT UNIQUE)');
        $database->close();
    }

    private function seedSource(): void
    {
        $source = new SQLite3($this->sourcePath);
        $source->exec("INSERT INTO organizations (id, name, slug) VALUES (1, 'Legacy organization', 'legacy-org')");
        $source->exec("INSERT INTO projects (id, organization_id, name) VALUES (2, 1, 'Legacy project')");
        $source->exec("INSERT INTO users (id, email, password, github_id) VALUES (1, 'owner@example.test', 'source-password', 'github-user-1')");
        $event = $source->prepare('INSERT INTO events (id, parentable_type, parentable_id) VALUES (2, :type, 2)');
        $event->bindValue(':type', 'App\\Models\\Build', SQLITE3_TEXT);
        $event->execute();
        $source->close();
    }

    private function seedBootstrapTarget(): void
    {
        DB::connection('deployer')->table('organizations')->insert([
            'id' => 1,
            'name' => 'Bootstrap organization',
            'slug' => 'bootstrap',
        ]);
        DB::connection('deployer')->table('projects')->insert([
            'id' => 1,
            'organization_id' => 1,
            'name' => 'Bootstrap project',
        ]);
        DB::connection('deployer')->table('events')->insert([
            'id' => 1,
            'parentable_type' => 'App\\Modules\\Deployer\\Models\\Build',
            'parentable_id' => 1,
        ]);
        DB::connection('deployer')->table('users')->insert([
            'id' => 1,
            'email' => 'OWNER@example.test',
            'password' => 'target-password',
            'github_id' => null,
        ]);
    }

    private function sourceEventType(): string
    {
        $source = new SQLite3($this->sourcePath, SQLITE3_OPEN_READONLY);
        $type = $source->querySingle('SELECT parentable_type FROM events WHERE id = 2');
        $source->close();

        return (string) $type;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isDot()) {
                continue;
            }

            if ($item->isDir()) {
                $this->removeDirectory($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
