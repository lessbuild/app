<?php

namespace App\Console\Commands;

use App\Models\AccessRequest;
use Illuminate\Console\Command;

class PruneAccessRequestsCommand extends Command
{
    protected $signature = 'buildpusher:access-requests:prune {--days= : Retain completed access requests for this many days}';

    protected $description = 'Prune declined and accepted access requests after the configured retention window';

    public function handle(): int
    {
        $value = $this->option('days') ?: config('lessbuild.access_request_retention_days');
        $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 30]]);
        if ($days === false) {
            $this->error('Retention days must be an integer of at least 30.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = AccessRequest::query()
            ->where(fn ($query) => $query->where('status', 'declined')->orWhereNotNull('accepted_at'))
            ->where('updated_at', '<', $cutoff)
            ->delete();
        $this->info("Pruned {$deleted} completed access request(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
