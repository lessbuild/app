<?php

namespace App\Console\Commands;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Services\ApplicationConfigurationDelivery;
use App\Services\ApplicationConfigurationResults;
use Illuminate\Console\Command;
use Throwable;

class ProcessConfigurationOperations extends Command
{
    protected $signature = 'buildpusher:configuration:process {--limit=100 : Maximum due operations to inspect (1-500)}';

    protected $description = 'Deliver due configuration operations and reconcile recorded deployment outcomes';

    public function handle(ApplicationConfigurationDelivery $delivery, ApplicationConfigurationResults $results): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Limit must be an integer between 1 and 500.');

            return self::FAILURE;
        }
        $operations = ConfigurationOperation::query()
            ->whereIn('status', ['pending', 'blocked', 'build_created', 'awaiting_approval', 'delivery_failed', 'delivering', 'delivered'])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        $failures = 0;
        foreach ($operations as $operation) {
            try {
                $delivery->deliver($operation);
                $results->refresh($operation->application);
                ConfigurationApplication::query()->whereHas('referencedOperations', fn ($query) => $query->where('configuration_operations.id', $operation->id))
                    ->each(fn ($application) => $results->refresh($application));
                // Round-robin polling prevents waiting approvals from starving new work.
                ConfigurationOperation::query()->whereKey($operation->id)
                    ->whereNotIn('status', ['succeeded', 'failed', 'canceled', 'delivering'])
                    ->update(['available_at' => now()->addMinute()]);
            } catch (Throwable) {
                $failures++;
                // Do not print or persist exception messages containing credentials.
                ConfigurationOperation::query()->whereKey($operation->id)
                    ->update(['failure_code' => 'operation_processing_failed', 'available_at' => now()->addMinute()]);
            }
        }
        $this->info('Inspected '.$operations->count().' configuration operations; '.$failures.' processing errors.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
