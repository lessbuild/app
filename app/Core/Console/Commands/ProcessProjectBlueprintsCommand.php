<?php

namespace App\Core\Console\Commands;

use App\Core\Models\ProjectBlueprintStep;
use App\Core\Services\Blueprints\ProcessProjectBlueprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ProcessProjectBlueprintsCommand extends Command
{
    protected $signature = 'project-blueprints:process {--limit=100 : Maximum due product steps to process}';

    protected $description = 'Resume accepted project blueprints using product-owned idempotent provisioning receipts';

    public function handle(ProcessProjectBlueprint $process): int
    {
        if (! Schema::connection('core')->hasTable('project_blueprint_steps')) {
            $this->warn('The blueprint schema is not installed.');

            return self::SUCCESS;
        }
        $ids = ProjectBlueprintStep::query()->where(function ($query): void {
            $query->where(fn ($pending) => $pending->whereIn('status', ['pending', 'waiting'])
                ->where(fn ($due) => $due->whereNull('available_at')->orWhere('available_at', '<=', now())))
                ->orWhere(fn ($expired) => $expired->where('status', 'processing')->where('lease_expires_at', '<=', now()));
        })->orderBy('updated_at')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            $process->handle((string) $id);
        }
        $this->info('Processed '.$ids->count().' due blueprint product steps.');

        return self::SUCCESS;
    }
}
