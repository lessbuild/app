<?php

namespace App\Jobs\Database;

use App\Models\DatabaseClone;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class CloneDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /**
     * Capture the database clone request to execute from its persisted source and target.
     *
     * @param  int  $cloneId  Persisted clone request identifying the source and target database resources.
     */
    public function __construct(public readonly int $cloneId) {}

    /**
     * Transfer the database between compatible resources on the same server into a nonproduction target; record running and terminal state, and record then rethrow failures.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     */
    public function handle(Runner $runner): void
    {
        $clone = DatabaseClone::query()->with(['source.environment.website.server', 'target.environment.website.server'])->find($this->cloneId);
        if (! $clone) {
            return;
        }
        $clone->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
        try {
            $sourceServer = $clone->source->environment?->website?->server;
            $targetServer = $clone->target->environment?->website?->server;
            if (! $sourceServer || ! $targetServer || ! $sourceServer->is($targetServer)) {
                throw new RuntimeException('Database cloning currently requires source and target on the same server.');
            }
            if ($clone->source->type !== $clone->target->type || $clone->target->environment->type === 'production') {
                throw new RuntimeException('The clone target is not safe.');
            }
            $source = $this->connection($clone->source->configuration['variables'] ?? []);
            $target = $this->connection($clone->target->configuration['variables'] ?? []);
            if ($clone->source->type === 'postgresql') {
                $command = 'set -o pipefail; PGPASSWORD='.escapeshellarg($source['password']).' pg_dump --no-owner --no-acl -h 127.0.0.1 -U '.$source['username'].' '.$source['database'].' | PGPASSWORD='.escapeshellarg($target['password']).' psql --set=ON_ERROR_STOP=1 -h 127.0.0.1 -U '.$target['username'].' '.$target['database'];
            } else {
                $command = 'set -o pipefail; MYSQL_PWD='.escapeshellarg($source['password']).' mysqldump --single-transaction -h 127.0.0.1 -u '.$source['username'].' '.$source['database'].' | MYSQL_PWD='.escapeshellarg($target['password']).' mysql -h 127.0.0.1 -u '.$target['username'].' '.$target['database'];
            }
            $result = $runner->server($sourceServer)->create(false)->execute($command);
            if (! $result->isSuccessful()) {
                throw new RuntimeException('The database transfer command failed.');
            }
            $clone->update(['status' => 'succeeded', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $clone->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    /**
     * Validate database and account identifiers and extract the password from stored resource variables.
     *
     * @param  array<string, mixed>  $variables  Resource environment variables containing DB_DATABASE, DB_USERNAME, and optional DB_PASSWORD.
     * @return array{database:string,username:string,password:string} Validated source or target connection values for the remote transfer command.
     *
     * @throws RuntimeException If the configured database or account identifier is unsafe.
     */
    private function connection(array $variables): array
    {
        foreach (['DB_DATABASE' => 'database', 'DB_USERNAME' => 'username'] as $key => $label) {
            if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', (string) ($variables[$key] ?? ''))) {
                throw new RuntimeException("Unsafe {$label} identifier.");
            }
        }

        return ['database' => $variables['DB_DATABASE'], 'username' => $variables['DB_USERNAME'], 'password' => (string) ($variables['DB_PASSWORD'] ?? '')];
    }
}
