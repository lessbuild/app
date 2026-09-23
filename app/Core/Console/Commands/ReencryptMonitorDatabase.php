<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Migration\ReencryptMonitorDatabaseValues;
use Illuminate\Console\Command;

final class ReencryptMonitorDatabase extends Command
{
    protected $signature = 'platform:reencrypt-monitor-database
        {--apply : Re-encrypt Monitor database values with the unified application key; default is a read-only preview}';

    protected $description = 'Preview or re-encrypt Monitor encrypted database values for the unified application key';

    public function handle(ReencryptMonitorDatabaseValues $reencrypter): int
    {
        $apply = (bool) $this->option('apply');
        $this->components->info($apply
            ? 'Re-encrypting Monitor database values.'
            : 'Read-only Monitor database encryption preview.');

        $report = $reencrypter->run($apply);

        $this->table(
            ['Measure', 'Encrypted values'],
            [
                ['Values seen', $report['values_seen']],
                ['Ready to re-encrypt', $report['ready']],
                ['Re-encrypted in this run', $report['reencrypted']],
                ['Already using unified key', $report['already_current']],
                ['Held for manual review', $report['needs_review']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Pause Monitor writers before applying and keep a verified database backup.');
        }

        return $report['needs_review'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
