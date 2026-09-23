<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Schedule::command('project-connections:deliver')
    ->everyMinute()
    ->when(fn (): bool => Schema::connection('core')->hasTable('project_connection_deliveries')
        && Schema::connection('deployer')->hasTable('deployment_succeeded_outbox_events'))
    ->withoutOverlapping(5)
    ->onOneServer();
