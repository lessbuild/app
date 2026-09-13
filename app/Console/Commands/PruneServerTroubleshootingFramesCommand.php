<?php

namespace App\Console\Commands;

use App\Actions\Server\PruneServerTroubleshootingFramesAction;
use Illuminate\Console\Command;

class PruneServerTroubleshootingFramesCommand extends Command
{
    protected $signature = 'buildpusher:troubleshooting:frames:prune {--limit=500 : Maximum encrypted frames to remove}';

    protected $description = 'Prune expired troubleshooting input and output frames';

    /** Remove a bounded batch without reading or decrypting retained payloads. */
    public function handle(PruneServerTroubleshootingFramesAction $prune): int
    {
        $count = $prune->handle((int) $this->option('limit'));
        $this->info("Pruned {$count} troubleshooting frame(s).");

        return self::SUCCESS;
    }
}
