<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Migration\ImportMonitorWorkspacesAndApplicationsIntoCore;
use Illuminate\Console\Command;

final class ImportMonitorWorkspacesAndApplications extends Command
{
    protected $signature = 'platform:import-monitor-workspaces-applications
        {--apply : Write eligible Monitor workspaces, memberships, invitations, applications, and environments to Core; default is a read-only preview}';

    protected $description = 'Preview or import Monitor workspaces and applications into Core using explicit source-ID mappings';

    public function handle(ImportMonitorWorkspacesAndApplicationsIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing Monitor workspaces and applications into Core.'
            : 'Read-only Monitor workspace/application import preview.');

        $report = $importer->run($apply);

        $this->table(
            ['Measure', 'Workspaces / applications / environments'],
            [
                ['Workspaces seen', $report['workspaces_seen']],
                ['Workspaces ready', $report['workspaces_ready']],
                ['Workspaces imported', $report['workspaces_imported']],
                ['Workspaces already mapped', $report['workspaces_already_mapped']],
                ['Workspaces blocked', $report['workspaces_blocked']],
                ['Unmapped workspace members', $report['unmapped_members']],
                ['Invitations seen', $report['invitations_seen']],
                ['Invitations imported', $report['invitations_imported']],
                ['Applications seen', $report['applications_seen']],
                ['Applications ready', $report['applications_ready']],
                ['Applications imported', $report['applications_imported']],
                ['Applications already mapped', $report['applications_already_mapped']],
                ['Applications blocked', $report['applications_blocked']],
                ['Environments seen', $report['environments_seen']],
                ['Environments imported', $report['environments_imported']],
                ['Environments blocked', $report['environments_blocked']],
                ['Review mappings created', $report['review_records_created']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Reconcile Monitor account IDs and workspace ownership before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
