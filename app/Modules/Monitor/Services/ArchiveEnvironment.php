<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use Illuminate\Support\Facades\DB;

final class ArchiveEnvironment
{
    public function __construct(private readonly SuspendHeartbeats $heartbeats, private readonly SuspendQueueMonitors $queues) {}

    public function archive(Environment $environment): void
    {
        DB::connection('monitor')->transaction(function () use ($environment): void {
            Application::query()->lockForUpdate()->findOrFail($environment->application_id);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environment->id);
            $environment->ingestTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->heartbeats->environment($environment->id, revoke: true);
            $this->queues->environment($environment->id, revoke: true);
            $environment->delete();
        });
    }
}
