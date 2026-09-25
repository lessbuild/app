<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ArchiveEnvironment
{
    public function __construct(private readonly SuspendHeartbeats $heartbeats, private readonly SuspendQueueMonitors $queues) {}

    public function archive(Environment $environment, ?User $actor = null): void
    {
        DB::connection('monitor')->transaction(function () use ($environment, $actor): void {
            Application::query()->lockForUpdate()->findOrFail($environment->application_id);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environment->id);
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('delete', $environment);
            }
            $environment->ingestTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->heartbeats->environment($environment->id, revoke: true);
            $this->queues->environment($environment->id, revoke: true);
            $environment->delete();
        });
    }
}
