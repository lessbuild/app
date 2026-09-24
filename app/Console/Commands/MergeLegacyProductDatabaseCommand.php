<?php

namespace App\Console\Commands;

use App\Core\Services\Migration\MergeLegacyProductDatabase;
use Illuminate\Console\Command;
use Throwable;

class MergeLegacyProductDatabaseCommand extends Command
{
    protected $signature = 'platform:merge-product-database
                            {product : deployer, monitor, or analytics}
                            {source : Path to a frozen SQLite database snapshot}
                            {--apply : Back up the current database and import the snapshot}';

    protected $description = 'Preview or merge a preserved product database snapshot';

    public function handle(MergeLegacyProductDatabase $merger): int
    {
        try {
            $result = $merger->run(
                (string) $this->argument('product'),
                (string) $this->argument('source'),
                (bool) $this->option('apply'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($result['applied']
            ? 'Product database merge applied.'
            : 'Read-only merge preview completed.');
        $this->table(
            ['Measure', 'Count'],
            [
                ['Source business rows', $result['source_rows']],
                ['Rows imported', $result['rows_imported']],
                ['Existing rows preserved', $result['rows_preserved']],
                ['Excluded transient rows', $result['excluded_rows']],
                ['Bootstrap rows relocated', $result['bootstrap_rows_relocated']],
                ['Legacy morph values rewritten', $result['morph_values_rewritten']],
                ['Missing provider IDs restored', $result['account_provider_ids_restored']],
            ],
        );

        if ($result['backup_path'] !== null) {
            $this->line('Protected pre-merge backup: '.$result['backup_path']);
        }

        return self::SUCCESS;
    }
}
