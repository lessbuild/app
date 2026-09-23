<?php

namespace Tests\Feature\Monitor;

use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\IngestReceipt;
use App\Modules\Monitor\Models\MonitorCheck;
use App\Modules\Monitor\Services\AlertDeliveryQueue;
use App\Modules\Monitor\Services\MonitorQueue;
use App\Modules\Monitor\Services\Telemetry\TelemetryQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class QueueDatabaseIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'deployer']);
    }

    public function test_legacy_database_queue_storage_stays_on_deployer_when_core_is_the_default(): void
    {
        config(['database.default' => 'core']);

        $this->assertSame('deployer', config('queue.connections.database.connection'));
        $this->assertSame('deployer', config('queue.failed.database'));

        Schema::connection('deployer')->create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Queue::connection('database')->push(new QueueDatabaseIsolationProbe, '', 'default');

        $this->assertSame(1, DB::connection('deployer')->table('jobs')->count());
        $this->assertFalse(Schema::connection('core')->hasTable('jobs'));
    }

    public function test_telemetry_dispatch_requires_a_monitor_transaction_when_deployer_is_in_a_transaction(): void
    {
        config(['monitor.beacon.telemetry.queue_connection' => 'missing']);
        DB::connection('deployer')->beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Telemetry dispatch must share the receipt transaction.');

            app(TelemetryQueue::class)->dispatch(new IngestReceipt);
        } finally {
            DB::connection('deployer')->rollBack();
        }
    }

    public function test_check_dispatch_requires_a_monitor_transaction_when_deployer_is_in_a_transaction(): void
    {
        config(['queue.connections.checks' => ['driver' => 'sync']]);
        DB::connection('deployer')->beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Checks require a primary database queue and an open scheduling transaction.');

            app(MonitorQueue::class)->dispatch(new MonitorCheck);
        } finally {
            DB::connection('deployer')->rollBack();
        }
    }

    public function test_alert_dispatch_requires_a_monitor_transaction_when_deployer_is_in_a_transaction(): void
    {
        config(['queue.connections.alerts' => ['driver' => 'sync']]);
        DB::connection('deployer')->beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Alerts require a primary database queue and an open outbox transaction.');

            app(AlertDeliveryQueue::class)->dispatch(new AlertDelivery);
        } finally {
            DB::connection('deployer')->rollBack();
        }
    }
}

final class QueueDatabaseIsolationProbe implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
