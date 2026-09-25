<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ArchiveApplication
{
    public function __construct(private readonly SuspendHeartbeats $heartbeats, private readonly SuspendQueueMonitors $queues) {}

    public function archive(Application $application, ?User $actor = null): void
    {
        DB::connection('monitor')->transaction(function () use ($application, $actor): void {
            $application = Application::query()->lockForUpdate()->findOrFail($application->id);
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('delete', $application);
            }
            IngestToken::query()
                ->whereIn('environment_id', $application->environments()->withTrashed()->select('id'))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $this->heartbeats->application($application->id);
            $this->queues->application($application->id);
            $application->delete();
            $application->increment('lifecycle_revision');
        });
    }
}
