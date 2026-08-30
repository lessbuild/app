<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class DatabaseBackupCommandTest extends TestCase
{
    private string $temporaryDirectory;

    private string $connectionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = storage_path('framework/testing/database-backup-'.Str::uuid());
        $this->connectionName = 'backup_test_'.Str::random(8);
        File::ensureDirectoryExists($this->temporaryDirectory);
        File::put($this->temporaryDirectory.'/source.sqlite', '');
        config([
            "database.connections.{$this->connectionName}" => [
                'driver' => 'sqlite',
                'database' => $this->temporaryDirectory.'/source.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'lessbuild.database_backup_directory' => $this->temporaryDirectory.'/backups',
            'lessbuild.database_backup_retention_days' => 7,
        ]);

        $connection = DB::connection($this->connectionName);
        $connection->statement('CREATE TABLE backup_proof (value TEXT NOT NULL)');
        $connection->table('backup_proof')->insert(['value' => 'consistent snapshot']);
    }

    protected function tearDown(): void
    {
        DB::purge($this->connectionName);
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_command_creates_a_consistent_restricted_sqlite_snapshot(): void
    {
        $exitCode = Artisan::call('lessbuild:backup', ['--connection' => $this->connectionName]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $backups = File::glob($this->temporaryDirectory.'/backups/automatic-database-*.sqlite');
        $this->assertCount(1, $backups);
        $this->assertSame(0640, fileperms($backups[0]) & 0777);

        $snapshot = new PDO('sqlite:'.$backups[0]);
        $this->assertSame('consistent snapshot', $snapshot->query('SELECT value FROM backup_proof')->fetchColumn());
        $this->assertSame('ok', $snapshot->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function test_command_prunes_only_expired_automatic_backups(): void
    {
        $backupDirectory = $this->temporaryDirectory.'/backups';
        File::ensureDirectoryExists($backupDirectory);
        $expired = $backupDirectory.'/automatic-database-20000101-000000-000000.sqlite';
        $recent = $backupDirectory.'/automatic-database-20990101-000000-000000.sqlite';
        $manual = $backupDirectory.'/database-before-release.sqlite';
        File::put($expired, 'expired');
        File::put($recent, 'recent');
        File::put($manual, 'manual');
        touch($expired, now()->subDays(8)->getTimestamp());

        $this->assertSame(0, Artisan::call('lessbuild:backup', ['--connection' => $this->connectionName]));

        $this->assertFileDoesNotExist($expired);
        $this->assertFileExists($recent);
        $this->assertFileExists($manual);
    }

    public function test_command_rejects_in_memory_databases_without_creating_files(): void
    {
        $memoryConnection = $this->connectionName.'_memory';
        config(["database.connections.{$memoryConnection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        $this->assertSame(1, Artisan::call('lessbuild:backup', ['--connection' => $memoryConnection]));
        $this->assertStringContainsString('file-backed SQLite database is required', Artisan::output());
        DB::purge($memoryConnection);
    }
}
