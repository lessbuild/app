<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Services\Migration\ImportFeedbackIntoCore;
use Illuminate\Console\Command;

final class ImportDeployerFeedback extends Command
{
    protected $signature = 'platform:import-deployer-feedback
        {--apply : Copy mapped Deployer feedback into Core; default is a read-only preview}';

    protected $description = 'Preview or import Deployer feedback after its workspace and user maps are reconciled';

    public function handle(ImportFeedbackIntoCore $importer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Importing reconciled Deployer feedback into Core.'
            : 'Read-only Deployer feedback import preview.');

        $report = $importer->run($apply);

        $this->table(
            ['Measure', 'Records'],
            [
                ['Feedback seen', $report['feedback_seen']],
                ['Feedback ready', $report['feedback_ready']],
                ['Feedback imported', $report['feedback_imported']],
                ['Feedback updated from source', $report['feedback_updated']],
                ['Feedback already current', $report['feedback_already_current']],
                ['Feedback blocked', $report['feedback_blocked']],
                ['Review mappings created', $report['review_records_created']],
            ],
        );

        if (! $apply) {
            $this->line('No data was changed. Reconcile each Deployer organization and referenced user before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
