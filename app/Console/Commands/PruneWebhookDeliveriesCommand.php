<?php

namespace App\Console\Commands;

use App\Models\Build;
use App\Models\RepositoryWebhookDelivery;
use Illuminate\Console\Command;

class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'lessbuild:webhooks:prune
        {--days= : Retain completed webhook deliveries for this many days}';

    protected $description = 'Prune expired webhook delivery history while preserving active deliveries';

    public function handle(): int
    {
        $days = $this->retentionDays();
        if ($days === null) {
            $this->error('Retention days must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        RepositoryWebhookDelivery::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($query): void {
                $query
                    ->whereIn('status', [
                        RepositoryWebhookDelivery::STATUS_UNAVAILABLE,
                        RepositoryWebhookDelivery::STATUS_SUPERSEDED,
                    ])
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', RepositoryWebhookDelivery::STATUS_QUEUED)
                            ->where(function ($query): void {
                                $query
                                    ->whereDoesntHave('build')
                                    ->orWhereHas('build', fn ($query) => $query
                                        ->whereIn('status', Build::TERMINAL_STATUSES));
                            });
                    });
            })
            ->orderBy('id')
            ->chunkById(250, function ($deliveries) use (&$deleted): void {
                $deleted += RepositoryWebhookDelivery::query()
                    ->whereKey($deliveries->modelKeys())
                    ->delete();
            });

        $this->info("Pruned {$deleted} webhook delivery record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function retentionDays(): ?int
    {
        $value = $this->option('days');
        $value = $value === null || $value === ''
            ? config('lessbuild.webhook_delivery_retention_days')
            : $value;
        $days = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $days === false ? null : $days;
    }
}
