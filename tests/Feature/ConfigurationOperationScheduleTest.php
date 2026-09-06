<?php

namespace Tests\Feature;

use App\Console\Kernel;
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
        Schema::shouldReceive('hasTable')->with('configuration_operations')->times(3)->andReturn(true);
        Schema::shouldReceive('hasTable')->with('configuration_operation_receipts')->times(3)->andReturn(false, true, true);
        Schema::shouldReceive('hasColumn')->with('configuration_operations', 'retry_of_operation_id')->twice()->andReturn(false, true);
        $this->assertFalse($event->filtersPass($this->app));
        $this->assertFalse($event->filtersPass($this->app));
        $this->assertTrue($event->filtersPass($this->app));
    }
}
