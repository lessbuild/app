<?php

declare(strict_types=1);

namespace App\Support\Infrastructure;

use App\Models\BackupDestination;
use App\Models\Website;
use RuntimeException;

/** The restic repository for a website (one per website in the destination's bucket) as shell environment assignments. */
final class ResticRepository
{
    public static function repository(BackupDestination $destination, Website $website): string
    {
        $endpoint = rtrim($destination->endpoint, '/');
        if (! str_starts_with($endpoint, 'https://')) {
            throw new RuntimeException('Backup destinations must use HTTPS.');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/iD', $destination->bucket) !== 1) {
            throw new RuntimeException('The backup bucket name is invalid.');
        }
        $prefix = trim($destination->path_prefix, '/');
        if (preg_match('/\A[a-zA-Z0-9._\/-]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The backup path prefix is invalid.');
        }

        return "s3:{$endpoint}/{$destination->bucket}/{$prefix}/websites/{$website->id}";
    }

    /** `KEY='value' …` to put before each restic command. */
    public static function environment(BackupDestination $destination, Website $website): string
    {
        return implode(' ', [
            'AWS_ACCESS_KEY_ID='.escapeshellarg($destination->access_key),
            'AWS_SECRET_ACCESS_KEY='.escapeshellarg($destination->secret_key),
            'AWS_DEFAULT_REGION='.escapeshellarg($destination->region),
            'RESTIC_PASSWORD='.escapeshellarg($destination->repository_password),
            'RESTIC_REPOSITORY='.escapeshellarg(self::repository($destination, $website)),
        ]);
    }
}
