<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\BackupDestination;
use App\Models\User;
use App\Services\Infrastructure\S3StorageProbe;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class CheckBackupDestination
{
    /**
     * Checks that a backup destination's bucket can be written.
     *
     * @param  S3StorageProbe  $probe  Writes, reads and deletes a small test object.
     */
    public function __construct(private readonly S3StorageProbe $probe) {}

    /**
     * Write, read and delete a test object. Returns the error (with any credentials blanked out), or null when it works.
     *
     * @param  User  $actor
     * @param  BackupDestination  $destination
     * @return string|null
     */
    public function handle(User $actor, BackupDestination $destination): ?string
    {
        Gate::forUser($actor)->authorize('update', $destination);
        try {
            $this->probe->check($destination);
        } catch (Throwable $exception) {
            $message = $exception->getMessage() ?: 'The check failed.';
            foreach ([$destination->access_key, $destination->secret_key, $destination->repository_password] as $secret) {
                $message = $secret === '' ? $message : str_replace($secret, '[redacted]', $message);
            }
            $message = str($message)->limit(1000)->toString();
            $destination->forceFill(['last_verified_at' => null, 'last_error' => $message])->save();

            return $message;
        }
        $destination->forceFill(['last_verified_at' => now(), 'last_error' => null])->save();

        return null;
    }
}
