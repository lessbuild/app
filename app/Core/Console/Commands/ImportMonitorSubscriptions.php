<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Migration\ImportMonitorSubscriptionsIntoCore;
use Illuminate\Console\Command;

final class ImportMonitorSubscriptions extends Command
{
    protected $signature = 'platform:import-monitor-subscriptions
        {--apply : Write Monitor plan, Stripe customer/subscription, and billing-event records to Core; default is a read-only preview}';

    protected $description = 'Preview or import Monitor workspace subscriptions and Stripe event history into Core';

    public function handle(ImportMonitorSubscriptionsIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing Monitor workspace subscriptions and billing history into Core.'
            : 'Read-only Monitor subscription import preview.');

        $report = $importer->run($apply);

        $this->table(
            ['Measure', 'Workspaces / subscriptions / events'],
            [
                ['Workspaces seen', $report['workspaces_seen']],
                ['Subscriptions ready', $report['subscriptions_ready']],
                ['Subscriptions imported', $report['subscriptions_imported']],
                ['Subscriptions already mapped', $report['subscriptions_already_mapped']],
                ['Subscriptions blocked', $report['subscriptions_blocked']],
                ['Stripe subscriptions imported', $report['stripe_subscriptions_imported']],
                ['Billing customers imported', $report['billing_customers_imported']],
                ['Billing events seen', $report['billing_events_seen']],
                ['Billing events ready', $report['billing_events_ready']],
                ['Billing events imported', $report['billing_events_imported']],
                ['Billing events already mapped', $report['billing_events_already_mapped']],
                ['Billing events blocked', $report['billing_events_blocked']],
                ['Review mappings created', $report['review_records_created']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Import Monitor workspaces first and review plan/status mismatches before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
