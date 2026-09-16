<?php

namespace App\Actions\Backup;

use App\Exceptions\BackupDestinationConnectionException;
use App\Models\BackupDestination;
use App\Services\S3CompatibleStorageProbe;
use Throwable;

class TestBackupDestinationAction
{
    public function __construct(
        private readonly S3CompatibleStorageProbe $probe,
    ) {}

    /**
     * Verify destination credentials with a temporary S3-compatible object without requiring a managed server.
     *
     * @throws BackupDestinationConnectionException When the destination cannot complete the probe.
     */
    public function handle(BackupDestination $destination): void
    {
        try {
            $this->probe->handle($destination);
        } catch (Throwable $exception) {
            $message = $this->safeError($destination, $exception);
            $destination->update([
                'last_verified_at' => null,
                'last_error' => $message,
            ]);

            throw new BackupDestinationConnectionException($message, previous: $exception);
        }

        $destination->update([
            'last_verified_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Bound diagnostics while removing every credential known to the destination.
     */
    private function safeError(BackupDestination $destination, Throwable $exception): string
    {
        $message = $exception->getMessage() ?: 'The backup destination test failed.';
        foreach ([$destination->access_key, $destination->secret_key, $destination->repository_password] as $secret) {
            if (filled($secret)) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return str($message)->limit(1000)->toString();
    }
}
