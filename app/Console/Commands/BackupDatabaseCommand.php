<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'lessbuild:backup
        {--connection= : Database connection to back up}';

    protected $description = 'Create a consistent SQLite database snapshot and prune expired automatic backups';

    public function handle(): int
    {
        $connection = DB::connection($this->option('connection') ?: null);
        if ($connection->getDriverName() !== 'sqlite') {
            $this->error('Automatic database backups currently support SQLite connections only.');

            return self::FAILURE;
        }

        $database = $connection->getConfig('database');
        if (! is_string($database) || $database === '' || $database === ':memory:') {
            $this->error('A file-backed SQLite database is required for backups.');

            return self::FAILURE;
        }

        $directory = (string) config('lessbuild.database_backup_directory');
        $retentionDays = max(1, (int) config('lessbuild.database_backup_retention_days'));
        File::ensureDirectoryExists($directory, 0750, true);

        $filename = 'automatic-database-'.now()->utc()->format('Ymd-His-u').'.sqlite';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $temporaryPath = $path.'.tmp';

        try {
            $this->snapshot($connection, $temporaryPath);
            $this->validateSnapshot($temporaryPath);

            if (! rename($temporaryPath, $path)) {
                throw new \RuntimeException('The completed database snapshot could not be finalized.');
            }

            chmod($path, 0640);
            $this->prune($directory, $retentionDays, $path);
        } catch (Throwable $exception) {
            File::delete($temporaryPath);
            report($exception);
            $this->error('Database backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Database backup created: {$path}");

        return self::SUCCESS;
    }

    private function snapshot(ConnectionInterface $connection, string $path): void
    {
        File::delete($path);
        $connection->statement('VACUUM INTO ?', [$path]);

        if (! File::isFile($path) || File::size($path) === 0) {
            throw new \RuntimeException('SQLite did not produce a valid database snapshot.');
        }
    }

    private function prune(string $directory, int $retentionDays, string $currentPath): void
    {
        $cutoff = now()->subDays($retentionDays)->getTimestamp();

        foreach (File::glob($directory.DIRECTORY_SEPARATOR.'automatic-database-*.sqlite') ?: [] as $path) {
            if ($path !== $currentPath && File::lastModified($path) < $cutoff) {
                File::delete($path);
            }
        }
    }

    private function validateSnapshot(string $path): void
    {
        $snapshot = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if ($snapshot->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new \RuntimeException('SQLite reported that the database snapshot is not valid.');
        }
    }
}
