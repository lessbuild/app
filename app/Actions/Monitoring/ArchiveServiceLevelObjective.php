<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Services\Monitoring\MonitorChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ArchiveServiceLevelObjective
{
    public function __construct(private readonly MonitorChanges $changes) {}

    /** Archive an objective. Burn-rate rules that use it stop finding data until they're changed. */
    public function handle(ServiceLevelObjective $objective, User $actor): void
    {
        DB::transaction(function () use ($objective, $actor): void {
            Gate::forUser($actor)->authorize('delete', $objective);
            $environment = $this->changes->lockScope($objective->environment->project, $actor, $objective->environment_id);
            ServiceLevelObjective::query()->where('environment_id', $environment->id)->lockForUpdate()->findOrFail($objective->id)->delete();
        }, attempts: 3);
    }
}
