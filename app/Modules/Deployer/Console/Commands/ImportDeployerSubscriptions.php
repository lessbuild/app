<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Services\Migration\ImportSubscriptionsIntoCore;
use Illuminate\Console\Command;

final class ImportDeployerSubscriptions extends Command
{
    protected $signature = 'platform:import-deployer-subscriptions
        {--apply : Write eligible Deployer customers, subscriptions, and current plan slots to Core; default is a read-only preview}
        {--shared-owner= : Source Deployer owner ID whose old billing is shared across organizations}
        {--organization= : Source Deployer organization ID that its owner assigned the old billing to}
        {--approved-by= : Reviewer identifier recorded with the ownership decision}
        {--evidence= : Non-secret reference for the workspace owner billing decision}';

    protected $description = 'Preview or import Deployer owner subscriptions into Core workspace product slots';

    public function handle(ImportSubscriptionsIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');
        $resolutionOptions = [
            'source_owner_user_id' => $this->option('shared-owner'),
            'source_organization_id' => $this->option('organization'),
            'approved_by' => $this->option('approved-by'),
            'evidence' => $this->option('evidence'),
        ];
        $providedResolutionOptions = array_filter($resolutionOptions, fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        if ($providedResolutionOptions !== [] && count($providedResolutionOptions) !== count($resolutionOptions)) {
            $this->components->error('Shared-owner resolution requires --shared-owner, --organization, --approved-by, and --evidence together.');

            return self::FAILURE;
        }

        $sharedOwnerResolution = $providedResolutionOptions === [] ? null : $resolutionOptions;

        $this->components->info($apply
            ? 'Importing eligible Deployer subscriptions into Core.'
            : 'Read-only Deployer subscription import preview.');

        if ($sharedOwnerResolution !== null) {
            $this->line($apply
                ? 'Applying the reviewed shared-owner workspace allocation.'
                : 'Previewing the explicit shared-owner workspace allocation; no data will be changed.');
        }

        $report = $importer->run($apply, $sharedOwnerResolution);

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
            $this->line('No data was changed. Review the report before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
