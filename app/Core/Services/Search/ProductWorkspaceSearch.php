<?php

namespace App\Core\Services\Search;

use App\Core\Models\Workspace;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

/** Resolves a product-local workspace to its authorized Core search context. */
final class ProductWorkspaceSearch
{
    public function __construct(
        private readonly ResolvePlatformUser $platformUsers,
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $access,
        private readonly WorkspaceSearch $search,
    ) {}

    /**
     * @return array{
     *   query:string,
     *   groups:list<array{product:string,label:string,results:list<array{type:string,title:string,subtitle:?string,url:string}>,count:int}>,
     *   unavailable:list<array{product:string,label:string}>
     * }|null
     */
    public function fromSourceWorkspace(
        Authenticatable $principal,
        string $product,
        string $sourceEntity,
        string|int $sourceWorkspaceId,
        string $query,
    ): ?array {
        try {
            $user = $this->platformUsers->resolve($principal, $product);

            if ($user === null) {
                return null;
            }

            $workspaceId = $this->identities->canonicalIdForSource(
                product: $product,
                sourceEntity: $sourceEntity,
                sourceId: (string) $sourceWorkspaceId,
                canonicalEntity: 'workspace',
            );

            if ($workspaceId === null) {
                return null;
            }

            $workspace = Workspace::query()->find($workspaceId);

            if ($workspace === null || $this->access->activeMembership($user, $workspace) === null) {
                return null;
            }

            return $this->search->forWorkspace($user, $workspace, $query);
        } catch (LostConnectionException|QueryException) {
            // Keep each product's established local search usable during a Core outage.
            return null;
        }
    }
}
