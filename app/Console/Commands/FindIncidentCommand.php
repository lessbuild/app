<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileObject;

class FindIncidentCommand extends Command
{
    protected $signature = 'lessbuild:incident {reference : UUID shown on the production error response}';

    protected $description = 'Locate a production incident reference in retained Laravel logs';

    public function handle(): int
    {
        $reference = strtolower(trim((string) $this->argument('reference')));
        if (! Str::isUuid($reference, 4)) {
            $this->error('The incident reference must be a valid version 4 UUID.');

            return self::FAILURE;
        }

        $directory = (string) config('lessbuild.incident_log_directory');
        if (! File::isDirectory($directory)) {
            $this->error('The configured incident log directory is unavailable.');

            return self::FAILURE;
        }

        $files = array_values(array_filter(
            File::glob($directory.DIRECTORY_SEPARATOR.'laravel*.log') ?: [],
            fn (string $path): bool => File::isFile($path) && ! is_link($path),
        ));
        usort($files, fn (string $left, string $right): int => File::lastModified($right) <=> File::lastModified($left));

        foreach ($files as $path) {
            $match = $this->find($path, $reference);
            if ($match === null) {
                continue;
            }

            $this->info("Incident {$reference} was found.");
            $this->line("Timestamp: {$match['timestamp']}");
            $this->line("Environment: {$match['environment']}");
            $this->line("Level: {$match['level']}");
            $this->line("Log location: {$path}:{$match['line']}");

            return self::SUCCESS;
        }

        $this->error("Incident {$reference} was not found in the retained Laravel logs.");

        return self::FAILURE;
    }

    /** @return array{timestamp: string, environment: string, level: string, line: int}|null */
    private function find(string $path, string $reference): ?array
    {
        $log = new SplFileObject($path, 'rb');
        $needle = '/"incident_id"\s*:\s*"'.preg_quote($reference, '/').'"/i';

        foreach ($log as $lineNumber => $line) {
            if (! is_string($line) || preg_match($needle, $line) !== 1) {
                continue;
            }

            preg_match('/^\[([^]]+)]\s+([^.\s]+)\.([A-Z]+):/', $line, $metadata);

            return [
                'timestamp' => $metadata[1] ?? 'unknown',
                'environment' => $metadata[2] ?? 'unknown',
                'level' => $metadata[3] ?? 'unknown',
                'line' => $lineNumber + 1,
            ];
        }

        return null;
    }
}
