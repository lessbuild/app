<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Schedule::command('deletions:process --limit=100')
    ->everyMinute()
    ->when(fn (): bool => Schema::connection('core')->hasTable('deletion_requests'))
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('project-connections:deliver')
    ->everyMinute()
    ->when(fn (): bool => Schema::connection('core')->hasTable('project_connection_deliveries')
        && Schema::connection('deployer')->hasTable('deployment_succeeded_outbox_events'))
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('workspace-product-access:retry-cleanup --apply --limit=100')
    ->everyFiveMinutes()
    ->when(fn (): bool => Schema::connection('core')->hasTable('workspace_product_access')
        && Schema::connection('core')->hasColumn('workspace_product_access', 'metadata')
        && Schema::connection('core')->hasTable('legacy_identity_maps'))
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('resource-restorations:process --limit=100')
    ->everyMinute()
    ->when(fn (): bool => Schema::connection('core')->hasTable('resource_restoration_requests'))
    ->withoutOverlapping(5)
    ->onOneServer();
