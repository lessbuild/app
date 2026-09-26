<?php

namespace Tests\Feature;

use App\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConfigurationOperationScheduleTest extends TestCase
{
    public function test_processor_is_scheduled_once_and_waits_for_its_migrations(): void
    {
        $events = collect(app(Kernel::class)->resolveConsoleSchedule()->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'buildpusher:configuration:process'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $schema = Schema::connection('deployer');
        $schema->dropIfExists('configuration_operation_receipts');
        $schema->dropIfExists('configuration_operations');

        try {
            $this->assertFalse($event->filtersPass($this->app));

            $schema->create('configuration_operations', fn (Blueprint $table) => $table->id());
            $schema->create('configuration_operation_receipts', fn (Blueprint $table) => $table->id());
            $this->assertFalse($event->filtersPass($this->app));

            $schema->table('configuration_operations', fn (Blueprint $table) => $table->unsignedBigInteger('retry_of_operation_id')->nullable());
            $this->assertTrue($event->filtersPass($this->app));
        } finally {
            $schema->dropIfExists('configuration_operation_receipts');
            $schema->dropIfExists('configuration_operations');
        }
    }
}
