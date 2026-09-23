<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Services\Migration\ImportSubscriptionsIntoCore;
use Illuminate\Console\Command;

final class ImportDeployerSubscriptions extends Command
{
    protected $signature = 'platform:import-deployer-subscriptions
        {--apply : Write eligible Deployer customers, subscriptions, and current plan slots to Core; default is a read-only preview}';

    protected $description = 'Preview or import Deployer owner subscriptions into Core workspace product slots';

    public function handle(ImportSubscriptionsIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing eligible Deployer subscriptions into Core.'
            : 'Read-only Deployer subscription import preview.');

        $report = $importer->run($apply);

        $this->table(
            ['Measure', 'Organizations / subscriptions'],
            [
                ['Organizations seen', $report['workspaces_seen']],
                ['Source subscriptions seen', $report['subscriptions_seen']],
                ['Subscriptions without an owner workspace', $report['subscriptions_without_workspace_owner']],
                ['Workspace plans ready', $report['subscriptions_ready']],
                ['Workspace plans imported', $report['subscriptions_imported']],
                ['Workspace plans already mapped', $report['subscriptions_already_mapped']],
                ['Workspace plans blocked', $report['subscriptions_blocked']],
                ['Stripe subscriptions imported', $report['stripe_subscriptions_imported']],
                ['Billing customers imported', $report['billing_customers_imported']],
                ['Review mappings created', $report['review_records_created']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Reconcile Deployer accounts/workspaces and review shared owner billing before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
