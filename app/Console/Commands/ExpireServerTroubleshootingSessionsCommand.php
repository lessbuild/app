<?php

namespace App\Console\Commands;

use App\Actions\Server\ExpireServerTroubleshootingSessionsAction;
use Illuminate\Console\Command;

class ExpireServerTroubleshootingSessionsCommand extends Command
{
    protected $signature = 'buildpusher:troubleshooting:sessions:expire {--limit=100 : Maximum expired sessions to mark}';

    protected $description = 'Expire inactive server troubleshooting session grants';

    /** Expire a bounded batch without contacting any server. */
    public function handle(ExpireServerTroubleshootingSessionsAction $expire): int
    {
        $count = $expire->handle((int) $this->option('limit'));
        $this->info("Expired {$count} troubleshooting session(s).");

        return self::SUCCESS;
    }
}
