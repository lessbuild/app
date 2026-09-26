<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Migration\ImportMonitorAccountsIntoCore;
use Illuminate\Console\Command;

final class ImportMonitorIdentities extends Command
{
    protected $signature = 'platform:import-monitor-identities
        {--apply : Write eligible Monitor accounts to Core; the default is a read-only preview}';

    protected $description = 'Preview or import Monitor account identities into Core without merging by email';

    public function handle(ImportMonitorAccountsIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing Monitor account identities into Core.'
            : 'Read-only Monitor identity import preview.');

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
            $this->line('No data was changed. Resolve identity conflicts before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
