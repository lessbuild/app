<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Migration\ImportAnalyticsWorkspacesAndSitesIntoCore;
use Illuminate\Console\Command;

final class ImportAnalyticsWorkspacesAndSites extends Command
{
    protected $signature = 'platform:import-analytics-workspaces-sites
        {--apply : Write eligible Analytics workspaces, memberships, invitations, and sites to Core; default is a read-only preview}';

    protected $description = 'Preview or import Analytics workspaces and sites into Core using explicit source-ID mappings';

    public function handle(ImportAnalyticsWorkspacesAndSitesIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing Analytics workspaces and sites into Core.'
            : 'Read-only Analytics workspace/site import preview.');

        $report = $importer->run($apply);

        $this->table(
            ['Measure', 'Workspaces / sites'],
            [
                ['Workspaces seen', $report['workspaces_seen']],
                ['Workspaces ready', $report['workspaces_ready']],
                ['Workspaces imported', $report['workspaces_imported']],
                ['Workspaces already mapped', $report['workspaces_already_mapped']],
                ['Workspaces blocked', $report['workspaces_blocked']],
                ['Unmapped workspace members', $report['unmapped_members']],
                ['Invitations seen', $report['invitations_seen']],
                ['Invitations imported', $report['invitations_imported']],
                ['Sites seen', $report['sites_seen']],
                ['Sites ready', $report['sites_ready']],
                ['Sites imported', $report['sites_imported']],
                ['Sites already mapped', $report['sites_already_mapped']],
                ['Sites blocked', $report['sites_blocked']],
                ['Review mappings created', $report['review_records_created']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Reconcile Analytics account IDs and workspace ownership before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
