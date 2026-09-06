<?php

namespace App\Jobs\Database;

use App\Models\EnvironmentResource;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class CollectDatabaseSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 240;

    public function __construct(public readonly int $resourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->resourceId;
    }

    public function handle(Runner $runner): void
    {
        $resource = EnvironmentResource::query()->with('environment.website.server')->find($this->resourceId);
        $server = $resource?->environment?->website?->server;
        $variables = $resource?->configuration['variables'] ?? [];
        abort_unless($resource && $server && in_array($resource->type, ['mysql', 'postgresql'], true), 422);
        $database = $this->identifier((string) ($variables['DB_DATABASE'] ?? ''));
        $username = $this->identifier((string) ($variables['DB_USERNAME'] ?? ''));
        $password = escapeshellarg((string) ($variables['DB_PASSWORD'] ?? ''));
        if ($resource->type === 'postgresql') {
            $command = "PGPASSWORD={$password} psql -h 127.0.0.1 -U {$username} -d {$database} -Atc \"SELECT 'size_bytes='||pg_database_size(current_database()); SELECT 'active_connections='||count(*) FROM pg_stat_activity WHERE datname=current_database(); SELECT 'schema_table='||schemaname||'.'||relname FROM pg_stat_user_tables ORDER BY 1 LIMIT 200;\"";
        } else {
            $command = "MYSQL_PWD={$password} mysql -h 127.0.0.1 -u {$username} --batch --skip-column-names {$database} -e \"SELECT CONCAT('size_bytes=',COALESCE(SUM(data_length+index_length),0)) FROM information_schema.tables WHERE table_schema=DATABASE(); SELECT CONCAT('active_connections=',COUNT(*)) FROM information_schema.processlist WHERE DB=DATABASE(); SELECT CONCAT('schema_table=',table_name) FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name LIMIT 200;\"";
        }
        $result = $runner->server($server)->create(false)->execute($command);
        if (! $result->isSuccessful()) {
            throw new RuntimeException('Database inspection failed.');
        }
        $values = ['tables' => []];
        foreach (preg_split('/\R/', trim($result->getOutput())) ?: [] as $line) {
            if (str_starts_with($line, 'schema_table=')) {
                $values['tables'][] = substr($line, 13);
            } elseif (preg_match('/\A(size_bytes|active_connections)=([0-9]+)\z/', $line, $match)) {
                $values[$match[1]] = (int) $match[2];
            }
        }
        $resource->snapshots()->create([
            'size_bytes' => $values['size_bytes'] ?? null,
            'active_connections' => $values['active_connections'] ?? null,
            'slow_queries' => 0,
            'schema_tables' => $values['tables'],
            'collected_at' => now(),
        ]);
        $resource->snapshots()->where('collected_at', '<', now()->subDays(30))->delete();
    }

    private function identifier(string $value): string
    {
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value)) {
            throw new RuntimeException('Database configuration contains an unsafe identifier.');
        }

        return $value;
    }
}
