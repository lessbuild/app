<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\OperationalIncident;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Facades\DB;

class ResolveOperationalIncidentAction
{
    /**
     * Atomically resolve an incident and append its attributed resolution event.
     */
    public function handle(OperationalIncident $incident, User $actor, string $resolution): void
    {
        DB::transaction(function () use ($incident, $actor, $resolution): void {
            $incident->update(['status' => OperationalIncident::STATUS_RESOLVED, 'active_key' => null, 'resolution' => $resolution, 'resolved_at' => now()]);
            $incident->events()->create(['actor_id' => $actor->id, 'type' => 'resolved', 'message' => $resolution, 'occurred_at' => now()]);
        });
    }
}
