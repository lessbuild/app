<?php

namespace App\Console\Commands;

use App\Services\SqliteBackupVerifier;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'lessbuild:backup
        {--connection= : Database connection to back up}';

    protected $description = 'Create a consistent SQLite database snapshot and prune expired automatic backups';

    /**
     * Create, verify, and publish an SQLite snapshot before pruning old automatic backups; report snapshot or verification failures.
     *
     * @param  SqliteBackupVerifier  $verifier  SQLite snapshot integrity verifier run before a backup is accepted.
     * @return int SUCCESS after publishing a verified backup, otherwise FAILURE.
     */
    public function handle(SqliteBackupVerifier $verifier): int
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
            $verifier->verify($temporaryPath);

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

    /**
     * Create a fresh SQLite VACUUM INTO snapshot and require a nonempty output file.
     *
     * @param  ConnectionInterface  $connection  Live SQLite connection whose consistent contents should be copied.
     * @param  string  $path  Temporary snapshot path, replaced before VACUUM INTO executes.
     *
     * @throws \RuntimeException If SQLite does not produce a nonempty snapshot file.
     */
    private function snapshot(ConnectionInterface $connection, string $path): void
    {
        File::delete($path);
        $connection->statement('VACUUM INTO ?', [$path]);

        if (! File::isFile($path) || File::size($path) === 0) {
            throw new \RuntimeException('SQLite did not produce a valid database snapshot.');
        }
    }

    /**
     * Delete expired automatic SQLite backups while retaining the snapshot just published.
     *
     * @param  string  $directory  Directory containing automatic database snapshots.
     * @param  int  $retentionDays  Age threshold in days for deleting earlier automatic snapshots.
     * @param  string  $currentPath  Newly verified snapshot that must survive this pruning pass.
     */
    private function prune(string $directory, int $retentionDays, string $currentPath): void
    {
        $cutoff = now()->subDays($retentionDays)->getTimestamp();

        foreach (File::glob($directory.DIRECTORY_SEPARATOR.'automatic-database-*.sqlite') ?: [] as $path) {
            if ($path !== $currentPath && File::lastModified($path) < $cutoff) {
                File::delete($path);
            }
        }
    }
}
