<?php

namespace App\Services;

use App\Models\BackupDestination;
use App\Models\Website;
use RuntimeException;

class ResticRepository
{
    /** @return array{repository: string, environment: string} */
    public function shell(BackupDestination $destination, Website $website): array
    {
        $endpoint = rtrim($destination->endpoint, '/');
        if (! str_starts_with($endpoint, 'https://')) {
            throw new RuntimeException('Backup destination must use HTTPS.');
        }
        if (! preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/iD', $destination->bucket)) {
            throw new RuntimeException('Backup bucket name is invalid.');
        }
        $prefix = trim($destination->path_prefix, '/');
        if (! preg_match('/\A[a-zA-Z0-9._\/-]+\z/D', $prefix)) {
            throw new RuntimeException('Backup path prefix is invalid.');
        }
        $repository = "s3:{$endpoint}/{$destination->bucket}/{$prefix}/websites/{$website->id}";
        $environment = implode(' ', [
            'AWS_ACCESS_KEY_ID='.escapeshellarg($destination->access_key),
            'AWS_SECRET_ACCESS_KEY='.escapeshellarg($destination->secret_key),
            'AWS_DEFAULT_REGION='.escapeshellarg($destination->region),
            'RESTIC_PASSWORD='.escapeshellarg($destination->repository_password),
            'RESTIC_REPOSITORY='.escapeshellarg($repository),
        ]);

        return ['repository' => $repository, 'environment' => $environment];
    }
}
