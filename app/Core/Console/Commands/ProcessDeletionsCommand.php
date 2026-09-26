<?php

namespace App\Core\Console\Commands;

use App\Core\Models\DeletionRequest;
use App\Core\Models\DeletionStep;
use App\Core\Services\Deletion\ProcessDeletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ProcessDeletionsCommand extends Command
{
    protected $signature = 'deletions:process {--limit=100 : Maximum due product steps to process}';

    protected $description = 'Resume accepted account and workspace deletion requests';

    public function handle(ProcessDeletion $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }
        if (! Schema::connection('core')->hasTable('deletion_requests')) {
            $this->error('Run the Core module migrations before processing deletion requests.');

            return self::FAILURE;
        }
        $recovered = $processor->recover();
        $steps = DeletionStep::query()->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($steps as $step) {
            $processor->process((string) $step);
        }
        DeletionRequest::query()->where('status', '!=', 'completed')->orderBy('id')->chunkById(100, function ($requests) use ($processor): void {
            foreach ($requests as $request) {
                $processor->advance((string) $request->getKey());
            }
        });
        $this->info(sprintf('Recovered %d expired lease(s); processed %d product step(s).', $recovered, $steps->count()));

        return self::SUCCESS;
    }
}
