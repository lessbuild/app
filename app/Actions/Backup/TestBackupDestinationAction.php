<?php

namespace App\Actions\Backup;

use App\Exceptions\BackupDestinationConnectionException;
use App\Models\BackupDestination;
use App\Models\Server;
use App\Models\Website;
use App\Services\ResticRepository;
use App\Services\Runner;
use RuntimeException;
use Throwable;

class TestBackupDestinationAction
{
    public function __construct(
        private readonly Runner $runner,
        private readonly ResticRepository $repositories,
    ) {}

    /**
     * Verify destination credentials from an active managed server and initialize an empty Restic repository when needed.
     *
     * @throws BackupDestinationConnectionException When the destination or selected server cannot complete the probe.
     */
    public function handle(BackupDestination $destination, Website $website): void
    {
        if ((int) $destination->organization_id !== (int) $website->organization_id) {
            throw new BackupDestinationConnectionException('The destination and website must belong to the same workspace.');
        }

        $server = $website->server;
        if (! $server || $server->provisioning_status !== Server::STATUS_ACTIVE) {
            throw new BackupDestinationConnectionException('Choose an active managed website to verify this destination.');
        }

        try {
            $restic = $this->repositories->shell($destination, $website);
            $environment = $restic['environment'];
            $command = <<<BASH
            set -Eeuo pipefail
            if ! command -v restic >/dev/null 2>&1; then
                apt-get update -qq
                DEBIAN_FRONTEND=noninteractive apt-get install -y -qq restic
            fi
            if ! {$environment} restic snapshots --json >/dev/null 2>&1; then
                {$environment} restic init >/dev/null
            fi
            {$environment} restic snapshots --json >/dev/null
            printf 'Backup destination verified.\n'
            BASH;
            $result = $this->runner->server($server)->create()->execute($command);
            if (! $result->isSuccessful()) {
                throw new RuntimeException(trim($result->getErrorOutput() ?: $result->getOutput()) ?: 'The remote backup destination test failed.');
            }
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
     * Bound remote diagnostics while removing every credential known to the destination.
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
