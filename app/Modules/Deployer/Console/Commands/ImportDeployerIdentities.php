<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Services\Migration\ImportAccountsIntoCore;
use Illuminate\Console\Command;

final class ImportDeployerIdentities extends Command
{
    protected $signature = 'platform:import-deployer-identities
        {--apply : Write eligible Deployer accounts to Core; the default is a read-only preview}';

    protected $description = 'Preview or import Deployer account identities into Core without merging by email';

    public function handle(ImportAccountsIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing Deployer account identities into Core.'
            : 'Read-only Deployer identity import preview.');

        $report = $importer->run($apply);

        $this->table(
            ['Measure', 'Accounts'],
            [
                ['Accounts seen', $report['accounts_seen']],
                ['Ready for import', $report['ready']],
                ['Already mapped', $report['already_mapped']],
                ['Needs manual review', $report['needs_review']],
                ['Imported in this run', $report['imported']],
                ['Review mappings created', $report['review_records_created']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Review identity collisions before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
