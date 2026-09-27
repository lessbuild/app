<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Enums\IngestStatus;
use App\Models\IngestReceipt;
use App\Models\IngestToken;
use App\Models\TelemetryEvent;
use App\Models\TelemetryUsageEntry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class QueuedTelemetryPersistenceTest extends TestCase
{
    use MonitoringHelpers;

    public function test_a_separate_worker_process_can_complete_a_committed_delivery_once(): void
    {
        $directory = sys_get_temp_dir().'/beacon-queue-test-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/database.sqlite';
        touch($database);
        chmod($database, 0600);
        $originalConnection = DB::getDefaultConnection();
        config([
            'database.connections.queue_persistence_test' => array_replace(config('database.connections.sqlite'), ['database' => $database, 'url' => null]),
            'monitoring.telemetry.queue_connection' => 'telemetry',
        ]);
        DB::setDefaultConnection('queue_persistence_test');
        Schema::clearResolvedInstance('db.schema');

        try {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'queue_persistence_test', '--force' => true, '--no-interaction' => true]));
            $token = IngestToken::factory()->withSecret('separate-worker-test')->create();
            $payload = ['batch_id' => 'durable-process-boundary', 'events' => [[
                'id' => 'one', 'type' => 'log', 'name' => 'Survived the process boundary',
            ]]];
            $this->withToken('separate-worker-test')->postJson(route('api.ingest'), $payload)->assertAccepted();
            $receipt = IngestReceipt::sole();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseEmpty('telemetry_events');
            DB::disconnect('queue_persistence_test');
            $worker = new Process([
                PHP_BINARY, 'artisan', 'queue:work', 'telemetry', '--queue=telemetry', '--once', '--sleep=0', '--no-interaction',
            ], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
                'APP_CONFIG_CACHE' => $directory.'/absent-config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'TELEMETRY_QUEUE_CONNECTION' => 'telemetry', 'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
            ], timeout: 45);

            $worker->run();

            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
            $this->assertSame(IngestStatus::Completed, $receipt->refresh()->status);
            $this->assertSame('Survived the process boundary', TelemetryEvent::sole()->name);
            $this->assertSame(1, TelemetryUsageEntry::sole()->event_count);
            $this->assertSame(1, $token->environment->refresh()->telemetry_event_count);
            foreach (['jobs', 'ingest_payloads', 'failed_jobs'] as $table) {
                $this->assertDatabaseEmpty($table);
            }

            $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.replayed', true);
            $worker->run();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertDatabaseCount('telemetry_usage_entries', 1);
            $this->assertDatabaseCount('telemetry_events', 1);
        } finally {
            DB::purge('queue_persistence_test');
            DB::setDefaultConnection($originalConnection);
            Schema::clearResolvedInstance('db.schema');

            foreach ([$database, $database.'-wal', $database.'-shm', $database.'-journal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($directory);
        }
    }
}
