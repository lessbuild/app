<?php

namespace App\Core\Services\Identity;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use Illuminate\Support\Facades\DB;

/** Ensures a Core workspace has one durable mapping to its module-owned workspace projection. */
final class EnsureProductWorkspaceMapping
{
    public function __construct(
        private readonly ProductWorkspaceProvisionerRegistry $provisioners,
        private readonly EnsurePlatformProductPrincipal $principals,
        private readonly LegacyIdentityResolver $identities,
    ) {}

    public function handle(string $product, Workspace $workspace): string
    {
        return app(CoordinateIdentityProjection::class)->run($product, $workspace, fn (): string => $this->project($product, $workspace));
    }

    private function project(string $product, Workspace $workspace): string
    {
        $workspace = Workspace::query()->findOrFail($workspace->getKey());
        abort_unless($workspace->status === 'active' && $workspace->archived_at === null, 409, 'The workspace is not active.');
        $sourceEntity = match ($product) {
            'deployer' => 'organization',
            'monitor', 'analytics' => 'workspace',
            default => abort(404),
        };
        $provisioner = $this->provisioners->get($product);
        abort_if($provisioner === null, 503, "The {$product} workspace adapter is unavailable.");

        $workspaceMappings = LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', $sourceEntity)
            ->where('canonical_entity', 'workspace')
            ->where('canonical_id', (string) $workspace->getKey())
            ->get();
        foreach ($workspaceMappings as $mapping) {
            abort_unless(
                $mapping->status === 'reconciled'
                    && is_string($mapping->source_id)
                    && ctype_digit($mapping->source_id),
                409,
                'The product workspace mapping needs reconciliation.',
            );
        }

        $owner = $workspace->owner()->firstOrFail();
        $this->principals->handle($product, $owner);
        $ownerPrincipalIds = $this->identities->sourceIdsFor($owner, $product);
        abort_unless(count($ownerPrincipalIds) === 1, 409, 'The workspace owner product identity needs reconciliation.');

        if ($workspaceMappings->isNotEmpty()) {
            foreach ($workspaceMappings as $mapping) {
                $provisioner->ensure(
                    ownerProductPrincipalId: $ownerPrincipalIds[0],
                    coreWorkspace: $workspace,
                    mappedProductWorkspaceId: (string) $mapping->source_id,
                );
            }

            return (string) $workspaceMappings->first()->source_id;
        }

        $productWorkspaceId = $provisioner->ensure(
            ownerProductPrincipalId: $ownerPrincipalIds[0],
            coreWorkspace: $workspace,
        );
        abort_unless(ctype_digit($productWorkspaceId), 409, 'The product workspace adapter returned an invalid workspace ID.');

        $sourceMapping = LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', $sourceEntity)
            ->where('source_id', $productWorkspaceId)
            ->first();

        if ($sourceMapping !== null) {
            abort_unless(
                $sourceMapping->status === 'reconciled'
                    && $sourceMapping->canonical_entity === 'workspace'
                    && (string) $sourceMapping->canonical_id === (string) $workspace->getKey(),
                409,
                'The product workspace mapping conflicts with another Core workspace.',
            );

            return $productWorkspaceId;
        }

        DB::connection('core')->transaction(function () use ($product, $sourceEntity, $productWorkspaceId, $workspace): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            abort_unless($workspace->status === 'active' && $workspace->archived_at === null, 409, 'The workspace is not active.');
            LegacyIdentityMap::query()->create([
                'source_product' => $product,
                'source_entity' => $sourceEntity,
                'source_id' => $productWorkspaceId,
                'canonical_entity' => 'workspace',
                'canonical_id' => (string) $workspace->getKey(),
                'status' => 'reconciled',
                'batch_key' => 'core-workspace-provisioning',
                'reconciliation_notes' => 'Created as the module-owned projection for a Core workspace.',
                'metadata' => ['provisioned_by' => 'core'],
                'imported_at' => now(),
                'reconciled_at' => now(),
            ]);
        }, attempts: 3);

        return $productWorkspaceId;
    }
}
