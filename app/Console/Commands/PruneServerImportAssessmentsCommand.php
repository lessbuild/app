<?php

namespace App\Console\Commands;

use App\Models\ServerImportAssessment;
use Illuminate\Console\Command;

class PruneServerImportAssessmentsCommand extends Command
{
    protected $signature = 'buildpusher:server-imports:prune';

    protected $description = 'Delete expired or consumed server import assessments and their encrypted credentials';

    /**
     * Delete consumed or expired server-import assessments and report the number removed.
     *
     * @return int SUCCESS after removing unusable assessments.
     */
    public function handle(): int
    {
        $deleted = ServerImportAssessment::query()
            ->where(fn ($query) => $query->where('expires_at', '<=', now())->orWhereNotNull('consumed_at'))
            ->delete();
        $this->info("Pruned {$deleted} server import assessment(s).");

        return self::SUCCESS;
    }
}
