<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Migration\ImportAnalyticsAccountsIntoCore;
use Illuminate\Console\Command;

final class ImportAnalyticsIdentities extends Command
{
    protected $signature = 'platform:import-analytics-identities
        {--apply : Write eligible Analytics accounts and passkeys to Core; the default is a read-only preview}';

    protected $description = 'Preview or import Analytics account identities into Core without merging by email';

    public function handle(ImportAnalyticsAccountsIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing Analytics account identities into Core.'
            : 'Read-only Analytics identity import preview.');

        $report = $importer->run($apply);

        $this->table(
            ['Measure', 'Accounts / passkeys'],
            [
                ['Accounts seen', $report['accounts_seen']],
                ['Ready for import', $report['ready']],
                ['Already mapped', $report['already_mapped']],
                ['Needs manual review', $report['needs_review']],
                ['Imported in this run', $report['imported']],
                ['Review mappings created', $report['review_records_created']],
                ['Passkeys seen', $report['passkeys_seen']],
                ['Passkeys imported', $report['passkeys_imported']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Resolve identity, encryption-key, and passkey conflicts before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
