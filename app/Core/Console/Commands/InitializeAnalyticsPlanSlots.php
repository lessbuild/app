<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Migration\InitializeAnalyticsPlanSlotsInCore;
use Illuminate\Console\Command;

final class InitializeAnalyticsPlanSlots extends Command
{
    protected $signature = 'platform:initialize-analytics-plan-slots
        {--apply : Write eligible no-charge legacy Analytics access slots to Core; default is a read-only preview}';

    protected $description = 'Preview or initialize independent Core plan slots for existing Analytics workspaces';

    public function handle(InitializeAnalyticsPlanSlotsInCore $initializer): int
    {
        $apply = (bool) $this->option('apply');

        $this->components->info($apply
            ? 'Initializing no-charge legacy Analytics access for eligible workspaces.'
            : 'Read-only Analytics plan-slot initialization preview.');

        $report = $initializer->run($apply);

        $this->table(
            ['Measure', 'Analytics workspaces / plan slots'],
            [
                ['Workspaces seen', $report['workspaces_seen']],
                ['Plan slots ready', $report['plan_slots_ready']],
                ['Plan slots initialized', $report['plan_slots_initialized']],
                ['Plan slots already initialized', $report['plan_slots_already_initialized']],
                ['Workspaces blocked', $report['workspaces_blocked']],
                ['Review mappings created', $report['review_records_created']],
            ],
        );

        $this->line('The baseline is $0, grants current Analytics features without limits, and preserves the configured retention windows. It creates no pricing, invoice, or Stripe record.');

        if (! $apply) {
            $this->line('No data was changed. Reconcile Analytics workspace mappings and review occupied plan slots before rerunning with --apply.');
        }

        return self::SUCCESS;
    }
}
