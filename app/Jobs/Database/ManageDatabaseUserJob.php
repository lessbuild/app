<?php

namespace App\Jobs\Database;

use App\Models\DatabaseUser;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ManageDatabaseUserJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $databaseUserId, public readonly string $action) {}

    public function uniqueId(): string
    {
        return $this->databaseUserId.'-'.$this->action;
    }

    public function handle(Runner $runner): void
    {
        $record = DatabaseUser::query()->with('resource.environment.website.server')->find($this->databaseUserId);
        if (! $record) {
            return;
        }
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
        if ($this->action === 'remove') {
            $record->delete();
        } else {
            $record->update(['applied_at' => now()]);
        }
    }

    private function identifier(string $value): string
    {
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value)) {
            throw new RuntimeException('Unsafe database identifier.');
        }

        return $value;
    }
}
