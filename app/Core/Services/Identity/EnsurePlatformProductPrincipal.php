<?php

namespace App\Core\Services\Identity;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;

/** Provision a module-owned identity on first Core-authenticated product access. */
final class EnsurePlatformProductPrincipal
{
    public function __construct(private readonly ProductPrincipalProvisionerRegistry $provisioners) {}

    public function handle(string $product, PlatformUser $platformUser): void
    {
        $provisioner = $this->provisioners->get($product);
        abort_if($provisioner === null, 404);

        // Existing and unresolved imported accounts are never linked by email.
        $existingMapping = LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', 'user')
            ->where('canonical_entity', 'user')
            ->where('canonical_id', (string) $platformUser->getAuthIdentifier())
            ->first();

        if ($existingMapping !== null) {
            if ($existingMapping->status === 'reconciled') {
                $provisioner->synchronizeMappedPrincipal((string) $existingMapping->source_id, $platformUser);
            }

            return;
        }

        $principalId = $provisioner->provision($platformUser);
        $mapping = LegacyIdentityMap::query()->firstOrCreate(
            [
                'source_product' => $product,
                'source_entity' => 'user',
                'source_id' => $principalId,
            ],
            [
                'canonical_entity' => 'user',
                'canonical_id' => (string) $platformUser->getAuthIdentifier(),
                'status' => 'reconciled',
                'batch_key' => 'shared-auth-provisioning',
                'reconciliation_notes' => 'Created through shared Core authentication on first product access.',
                'metadata' => ['provisioned_by' => 'core'],
                'imported_at' => now(),
                'reconciled_at' => now(),
            ],
        );

        abort_unless(
            $mapping->canonical_entity === 'user'
                && (string) $mapping->canonical_id === (string) $platformUser->getAuthIdentifier()
                && $mapping->status === 'reconciled',
            409,
            'The product identity mapping needs explicit reconciliation.',
        );
    }
}
