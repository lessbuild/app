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

    public function test_verifier_checks_every_retained_backup_and_reports_corruption(): void
    {
        $this->assertSame(0, Artisan::call('lessbuild:backup', ['--connection' => $this->connectionName]));
        $backup = File::glob($this->temporaryDirectory.'/backups/automatic-database-*.sqlite')[0];
        $manual = $this->temporaryDirectory.'/backups/database-before-release.sqlite';
        File::copy($backup, $manual);

        $exitCode = Artisan::call('lessbuild:backups:verify', ['--all' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Verified 2 backup file(s).', $output);

        $corrupt = $this->temporaryDirectory.'/backups/automatic-database-corrupt.sqlite';
        File::put($corrupt, 'not a sqlite database');
        $this->assertSame(1, Artisan::call('lessbuild:backups:verify', ['--all' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString("Invalid backup: {$corrupt}", $output);
        $this->assertStringContainsString('failed for 1 of 3 file(s)', $output);
        $this->assertFileExists($corrupt);
    }

    public function test_verifier_selects_the_latest_backup_or_a_specific_filename(): void
    {
        $this->assertSame(0, Artisan::call('lessbuild:backup', ['--connection' => $this->connectionName]));
        $latest = File::glob($this->temporaryDirectory.'/backups/automatic-database-*.sqlite')[0];
        $older = $this->temporaryDirectory.'/backups/database-older.sqlite';
        File::copy($latest, $older);
        touch($older, now()->subDay()->getTimestamp());
        clearstatcache();

        $exitCode = Artisan::call('lessbuild:backups:verify');
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString("Verified: {$latest}", $output);
        $this->assertStringNotContainsString($older, $output);

        $this->assertSame(0, Artisan::call('lessbuild:backups:verify', ['path' => basename($older)]));
        $this->assertStringContainsString("Verified: {$older}", Artisan::output());
        $this->assertSame(1, Artisan::call('lessbuild:backups:verify', [
            'path' => basename($older),
            '--all' => true,
        ]));
        $this->assertStringContainsString('either a specific backup path or --all', Artisan::output());
    }

    public function test_verifier_fails_when_no_backup_or_requested_file_exists(): void
    {
        $this->assertSame(1, Artisan::call('lessbuild:backups:verify'));
        $this->assertStringContainsString('No SQLite backups were found', Artisan::output());

        $missing = $this->temporaryDirectory.'/backups/missing.sqlite';
        $this->assertSame(1, Artisan::call('lessbuild:backups:verify', ['path' => $missing]));
        $output = Artisan::output();
        $this->assertStringContainsString("Invalid backup: {$missing}", $output);
        $this->assertStringContainsString('does not exist', $output);
    }
}
