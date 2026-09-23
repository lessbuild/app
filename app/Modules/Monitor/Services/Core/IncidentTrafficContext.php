<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Data\Projects\ProjectTrafficContextSnapshot;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\ResolveProjectTrafficContext;
use App\Modules\Monitor\Models\Incident;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

final class IncidentTrafficContext
{
    public function __construct(
        private readonly ResolvePlatformUser $platformUsers,
        private readonly ResolveProjectTrafficContext $contexts,
    ) {}

    /** @return Collection<int, ProjectTrafficContextSnapshot> */
    public function forIncident(?Authenticatable $principal, Incident $incident): Collection
    {
        if ($principal === null || ! $incident->opened_at instanceof DateTimeInterface) {
            return collect();
        }

        $user = $this->platformUsers->resolve($principal, 'monitor');
        $environmentId = $incident->source()?->environment?->getKey();

        if ($user === null || $environmentId === null) {
            return collect();
        }

        try {
            return $this->contexts->forMonitorEnvironment($user, (string) $environmentId, $incident->opened_at);
        } catch (LostConnectionException|QueryException) {
            return collect();
        }
    }
}
