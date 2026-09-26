<?php

namespace App\Core\Services\Restoration;

use App\Core\Exceptions\Restoration\ResourceRestorationBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\ResourceRestorationRequest;
use Illuminate\Support\Facades\DB;

final class RetryResourceRestoration
{
    public function __construct(private readonly ResourceRestorationAuthority $authority, private readonly ProductResourceRestorationRegistry $providers) {}

    public function retry(PlatformUser $actor, ResourceRestorationRequest $request): bool
    {
        if ($request->actor_id !== (string) $actor->getKey()) {
            throw new ResourceRestorationBlocked('restoration_actor_changed');
        }
        $target = $request->target();
        $this->authority->authorize($actor, $target);
        $provider = $this->providers->get($target->product, $target->resourceType);
        if ($provider === null) {
            throw new ResourceRestorationBlocked('restoration_unavailable');
        }
        // Recheck the native management role without taking any Core lock.
        $provider->inspect($actor, $target);

        return DB::connection('core')->transaction(function () use ($actor, $request, $target): bool {
            $locked = ResourceRestorationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $this->authority->authorize($actor, $target, lock: true);
            if (! hash_equals($locked->mapping_fingerprint, $this->authority->fingerprint($this->authority->bindings($target, $locked->states(), lock: true, actorId: (string) $actor->getKey())))) {
                throw new ResourceRestorationBlocked('resource_mapping_changed');
            }
            if (! in_array($locked->status, ['pending', 'blocked', 'failed'], true)) {
                return false;
            }
            // Attempts never reset: the generation also fences stale source workers.
            $locked->forceFill(['status' => 'pending', 'available_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => null, 'last_error_at' => null])->save();

            return true;
        });
    }
}
