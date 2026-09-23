<?php

namespace App\Core\Services;

use App\Core\Models\LegacyIdentityMap;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves a Core account to reconciled IDs retained in a product database.
 * A missing or pending mapping grants no access to legacy product records.
 */
final class LegacyIdentityResolver
{
    public function canonicalIdForSource(
        string $product,
        string $sourceEntity,
        string|int $sourceId,
        ?string $canonicalEntity = null,
    ): ?string {
        return LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', $sourceEntity)
            ->where('source_id', (string) $sourceId)
            ->where('canonical_entity', $canonicalEntity ?? $sourceEntity)
            ->where('status', 'reconciled')
            ->value('canonical_id');
    }

    /** @return list<string> */
    public function sourceIdsFor(Authenticatable|string $user, string $product, string $entity = 'user'): array
    {
        $canonicalId = $user instanceof Authenticatable
            ? (string) $user->getAuthIdentifier()
            : $user;

        return LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', $entity)
            ->where('canonical_entity', $entity)
            ->where('canonical_id', $canonicalId)
            ->where('status', 'reconciled')
            ->orderBy('source_id')
            ->pluck('source_id')
            ->map(static fn ($sourceId): string => (string) $sourceId)
            ->all();
    }

    /** @return list<string> */
    public function sourceIdsForCanonical(
        string $product,
        string $sourceEntity,
        string|int $canonicalId,
        ?string $canonicalEntity = null,
    ): array {
        return LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', $sourceEntity)
            ->where('canonical_entity', $canonicalEntity ?? $sourceEntity)
            ->where('canonical_id', (string) $canonicalId)
            ->where('status', 'reconciled')
            ->orderBy('source_id')
            ->pluck('source_id')
            ->map(static fn ($sourceId): string => (string) $sourceId)
            ->all();
    }
}
