<?php

namespace App\Core\Services\Identity;

use App\Core\Models\PlatformUser;
use App\Core\Services\LegacyIdentityResolver;
use Illuminate\Contracts\Auth\Authenticatable;

final class ResolvePlatformUser
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function resolve(Authenticatable $principal, string $sourceProduct): ?PlatformUser
    {
        if ($principal instanceof PlatformUser) {
            return $principal->status === 'active' ? $principal : null;
        }

        $canonicalId = $this->identities->canonicalIdForSource(
            product: $sourceProduct,
            sourceEntity: 'user',
            sourceId: (string) $principal->getAuthIdentifier(),
        );

        if ($canonicalId === null) {
            return null;
        }

        return PlatformUser::query()
            ->whereKey($canonicalId)
            ->where('status', 'active')
            ->first();
    }
}
