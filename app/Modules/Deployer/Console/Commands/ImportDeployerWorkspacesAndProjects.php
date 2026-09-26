<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Services\Migration\ImportWorkspacesAndProjectsIntoCore;
use Illuminate\Console\Command;

final class ImportDeployerWorkspacesAndProjects extends Command
{
    protected $signature = 'platform:import-deployer-workspaces-projects
        {--apply : Write eligible workspaces, projects, and environments to Core; the default is a read-only preview}';

    protected $description = 'Preview or import Deployer workspaces, projects, and environments into Core';

    public function handle(ImportWorkspacesAndProjectsIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing Deployer workspaces and projects into Core.'
            : 'Read-only Deployer workspace and project import preview.');

        $report = $importer->run($apply);

        $this->table(
            ['Measure', 'Count'],
            [
                ['Workspaces seen', $report['workspaces_seen']],
                ['Workspaces ready', $report['workspaces_ready']],
                ['Workspaces already mapped', $report['workspaces_already_mapped']],
                ['Workspaces blocked', $report['workspaces_blocked']],
                ['Projects seen', $report['projects_seen']],
                ['Projects ready', $report['projects_ready']],
                ['Projects already mapped', $report['projects_already_mapped']],
                ['Projects blocked', $report['projects_blocked']],
                ['Unmapped members excluded', $report['unmapped_members']],
                ['Invitations seen', $report['invitations_seen']],
                ['Workspaces imported', $report['workspaces_imported']],
                ['Projects imported', $report['projects_imported']],
                ['Invitations imported', $report['invitations_imported']],
                ['Environments imported', $report['environments_imported']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Import eligible accounts first, review this report, then rerun with --apply.');
        }

        return self::SUCCESS;
    }
}
