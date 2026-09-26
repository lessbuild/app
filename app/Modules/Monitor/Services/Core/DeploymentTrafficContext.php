<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Data\Projects\ProjectReleaseTrafficContextSnapshot;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\ResolveProjectTrafficContext;
use App\Modules\Monitor\Models\Deployment;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

final class DeploymentTrafficContext
{
    public function __construct(
        private readonly ResolvePlatformUser $platformUsers,
        private readonly ResolveProjectTrafficContext $contexts,
    ) {}

    /** @return Collection<int, ProjectReleaseTrafficContextSnapshot> */
    public function forDeployment(?Authenticatable $principal, Deployment $deployment, int $seconds): Collection
    {
        if ($principal === null || $seconds <= 0 || ! $deployment->deployed_at instanceof DateTimeInterface) {
            return collect();
        }

        $user = $this->platformUsers->resolve($principal, 'monitor');

        if ($user === null) {
            return collect();
        }

        try {
            return $this->contexts->forMonitorDeployment(
                $user,
                (string) $deployment->environment_id,
                $deployment->deployed_at,
                $seconds,
            );
        } catch (LostConnectionException|QueryException) {
            return collect();
        }
    }
}
