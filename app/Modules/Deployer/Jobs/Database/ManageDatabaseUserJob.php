<?php

namespace App\Modules\Deployer\Jobs\Database;

use App\Modules\Deployer\Models\DatabaseOperationRun;
use App\Modules\Deployer\Models\DatabaseUser;
use App\Modules\Deployer\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ManageDatabaseUserJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 7200;

    public int $tries = 5;

    public int $timeout = 600;

    /**
     * Capture the managed database user and whether its remote account should be removed or applied.
     *
     * @param  int  $databaseUserId  Stored database account whose remote privileges should be reconciled.
     * @param  string  $action  The literal remove deletes the account; apply reconciles its stored credentials and privileges.
     * @param  int|null  $operationRunId  Durable operation record created by the authorized request, when available.
     */
    public function __construct(
        public readonly int $databaseUserId,
        public readonly string $action,
        public readonly ?int $operationRunId = null,
    ) {}

    /**
     * Keep account application and removal jobs distinct while coalescing duplicates of each operation.
     *
     * @return string The database-user identifier combined with the requested action.
     */
    public function uniqueId(): string
    {
        return $this->databaseUserId.'-'.$this->action;
    }

    /** @return array<int, WithoutOverlapping> Serialize changes to one remote database credential. */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('database-user:'.$this->databaseUserId))
                ->shared()
                ->releaseAfter(15)
                ->expireAfter(3600),
        ];
    }

    /**
     * Apply database privileges and credentials or remove the remote account; persist the applied timestamp or delete its record only after successful execution.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     */
    public function handle(Runner $runner): void
    {
        abort_unless(in_array($this->action, ['apply', 'remove'], true), 422);

        $record = DatabaseUser::query()->with('resource.environment.website.server')->find($this->databaseUserId);
        $operationRun = $this->operationRun();

        abort_unless($this->operationRunId === null || $operationRun !== null, 404);

        if ($operationRun !== null && $record !== null) {
            abort_unless((string) $operationRun->environment_resource_id === (string) $record->environment_resource_id, 404);
        }

        if ($operationRun !== null && ! $operationRun->claim()) {
            return;
        }

        if (! $record) {
            if ($this->action === 'remove') {
                $operationRun?->markSucceeded();
            } else {
                $operationRun?->markFailed();
            }

            return;
        }

        try {
            $this->applyRemoteOperation($runner, $record, $operationRun);
        } catch (Throwable $exception) {
            $operationRun?->queueForRetry();

            throw $exception;
        }
    }

    private function applyRemoteOperation(Runner $runner, DatabaseUser $record, ?DatabaseOperationRun $operationRun): void
    {
        $resource = $record->resource;
        $server = $resource->environment?->website?->server;
        abort_unless($server, 422);
        $username = $this->identifier($record->username);
        $variables = $resource->configuration['variables'] ?? [];
        $database = $this->identifier((string) ($variables['DB_DATABASE'] ?? ''));
        $owner = $this->identifier((string) ($variables['DB_USERNAME'] ?? ''));
        $password = str_replace("'", "''", $record->password);
        if ($resource->type === 'postgresql') {
            $privilege = match ($record->privilege) {
                'read' => 'SELECT', 'write' => 'SELECT, INSERT, UPDATE, DELETE', default => 'ALL PRIVILEGES'
            };
            $sql = $this->action === 'remove'
                ? "REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM \"{$username}\"; REVOKE USAGE ON SCHEMA public FROM \"{$username}\"; REVOKE CONNECT ON DATABASE \"{$database}\" FROM \"{$username}\"; DROP ROLE IF EXISTS \"{$username}\";"
                : "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='{$username}') THEN CREATE ROLE \"{$username}\" LOGIN PASSWORD '{$password}'; ELSE ALTER ROLE \"{$username}\" PASSWORD '{$password}'; END IF; END \$\$; GRANT CONNECT ON DATABASE \"{$database}\" TO \"{$username}\"; GRANT USAGE ON SCHEMA public TO \"{$username}\"; GRANT {$privilege} ON ALL TABLES IN SCHEMA public TO \"{$username}\"; ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT {$privilege} ON TABLES TO \"{$username}\";";
            $command = 'sudo -u postgres psql --set=ON_ERROR_STOP=1 '.escapeshellarg($database).' -c '.escapeshellarg($sql);
        } else {
            $privilege = match ($record->privilege) {
                'read' => 'SELECT, SHOW VIEW', 'write' => 'SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES', default => 'ALL PRIVILEGES'
            };
            $sql = $this->action === 'remove'
                ? "DROP USER IF EXISTS '{$username}'@'localhost';"
                : "CREATE USER IF NOT EXISTS '{$username}'@'localhost' IDENTIFIED BY '{$password}'; ALTER USER '{$username}'@'localhost' IDENTIFIED BY '{$password}'; GRANT {$privilege} ON `{$database}`.* TO '{$username}'@'localhost'; FLUSH PRIVILEGES;";
            $command = 'mysql --protocol=socket -e '.escapeshellarg($sql);
        }
        $result = $runner->server($server)->create(false)->execute($command);
        if (! $result->isSuccessful()) {
            throw new RuntimeException('Database user operation failed.');
        }
        DB::connection('deployer')->transaction(function () use ($record, $operationRun): void {
            if ($this->action === 'remove') {
                $record->delete();
            } else {
                $record->update(['applied_at' => now()]);
            }

            $operationRun?->markSucceeded();
        });
    }

    public function failed(Throwable $exception): void
    {
        $this->operationRun()?->markFailed();
    }

    private function operationRun(): ?DatabaseOperationRun
    {
        if ($this->operationRunId === null) {
            return null;
        }

        return DatabaseOperationRun::query()
            ->whereKey($this->operationRunId)
            ->where('subject_id', $this->databaseUserId)
            ->where('operation', $this->action === 'remove' ? 'user_remove' : 'user_apply')
            ->first();
    }

    /**
     * Accept only SQL identifiers composed of an initial letter or underscore followed by letters, digits, or underscores.
     *
     * @param  string  $value  Database or account identifier from stored resource configuration.
     * @return string The unchanged identifier after validation.
     *
     * @throws RuntimeException If the identifier contains unsupported characters or begins with a digit.
     */
    private function identifier(string $value): string
    {
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value)) {
            throw new RuntimeException('Unsafe database identifier.');
        }

        return $value;
    }
}
