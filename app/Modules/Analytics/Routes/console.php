<?php

use App\Modules\Analytics\Jobs\RecordQueueWorkerHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('analytics:dispatch-pending')->everyMinute()->withoutOverlapping();
Artisan::command('analytics:health-probe', function (): int {
    RecordQueueWorkerHealth::dispatch();

    return 0;
})->purpose('Queue a bounded liveness probe for the Analytics background worker');
Schedule::command('analytics:health-probe')->everyMinute()->withoutOverlapping();
Schedule::command('analytics:prune')->daily()->withoutOverlapping();
