<?php

namespace App\Console\Commands;

use App\Services\SqliteBackupVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class VerifyDatabaseBackupsCommand extends Command
{
    protected $signature = 'lessbuild:backups:verify
        {path? : Specific SQLite backup path or filename}
        {--all : Verify every SQLite backup in the configured directory}';

    protected $description = 'Verify the integrity of the latest, a specific, or every retained SQLite backup';

    /**
     * Resolve the selected SQLite backups and report verification results without stopping after an individual verification failure.
     *
     * @param  SqliteBackupVerifier  $verifier  SQLite snapshot integrity verifier run before a backup is accepted.
     * @return int SUCCESS when every selected backup verifies, otherwise FAILURE for invalid selection, no backups, or verification errors.
     */
    public function handle(SqliteBackupVerifier $verifier): int
    {
        if ($this->argument('path') && $this->option('all')) {
            $this->error('Choose either a specific backup path or --all, not both.');

            return self::FAILURE;
        }

        $paths = $this->paths();
        if ($paths === []) {
            $this->error('No SQLite backups were found to verify.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($paths as $path) {
            try {
                $verifier->verify($path);
                $this->line("Verified: {$path}");
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error("Invalid backup: {$path} ({$exception->getMessage()})");
            }
        }

        if ($failed > 0) {
            $this->error("Backup verification failed for {$failed} of ".count($paths).' file(s).');

            return self::FAILURE;
        }

        $this->info('Verified '.count($paths).' backup file(s).');

        return self::SUCCESS;
    }

    /**
     * Resolve an explicit snapshot path, every discovered snapshot, or the newest snapshot from the backup directory.
     *
     * @return list<string> Selected snapshot paths; an empty list indicates no matching backup files.
     */
    private function paths(): array
    {
        $directory = (string) config('lessbuild.database_backup_directory');
        $argument = $this->argument('path');
        if (is_string($argument) && $argument !== '') {
            $path = File::isFile($argument) || str_contains($argument, DIRECTORY_SEPARATOR)
                ? $argument
                : $directory.DIRECTORY_SEPARATOR.basename($argument);

            return [$path];
        }

        $paths = File::glob($directory.DIRECTORY_SEPARATOR.'*.sqlite') ?: [];
        sort($paths, SORT_STRING);
        if ($this->option('all') || $paths === []) {
            return $paths;
        }

        usort($paths, fn (string $left, string $right): int => File::lastModified($right) <=> File::lastModified($left));

        return [$paths[0]];
    }
}
