<?php

namespace App\Core\Console\Commands;

use App\Core\Models\ResourceRestorationRequest;
use App\Core\Services\Restoration\ProcessResourceRestoration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ProcessResourceRestorationsCommand extends Command
{
    protected $signature = 'resource-restorations:process {--limit=100 : Maximum due requests to process}';

    protected $description = 'Recover expired restoration leases and process durable resource restorations';

    public function handle(ProcessResourceRestoration $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }
        if (! Schema::connection('core')->hasTable('resource_restoration_requests')) {
            $this->error('Run the Core module migrations before processing resource restorations.');

            return self::FAILURE;
        }
        $recovered = $processor->recoverExpiredLeases();
        $requests = ResourceRestorationRequest::query()->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('id');
        $results = [];
        foreach ($requests as $id) {
            $status = $processor->process((string) $id);
            $results[$status] = ($results[$status] ?? 0) + 1;
        }
        $this->info(sprintf('Recovered %d expired lease(s); processed %d restoration request(s).', $recovered, $requests->count()));
        foreach ($results as $status => $count) {
            $this->line($status.': '.$count);
        }

        return self::SUCCESS;
    }
}
