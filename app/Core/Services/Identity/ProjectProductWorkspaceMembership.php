<?php

namespace App\Core\Services\Identity;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;

/** Routes Core access changes through the owning product module's projector. */
final class ProjectProductWorkspaceMembership
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductWorkspaceMembershipProjectorRegistry $projectors,
    ) {}

    /**
     * @param  array<string, array{managed?:bool,role?:string,created_by_projection?:bool,previous_role?:?string}>  $previous
     * @return array<string, array{managed:bool,role:string,created_by_projection:bool,previous_role:?string}>
     */
    public function grant(
        string $product,
        PlatformUser $user,
        Workspace $workspace,
        string $role,
        array $previous = [],
    ): array {
        $projector = $this->projectors->get($product);
        abort_if($projector === null, 404);

        $principalIds = $this->identities->sourceIdsFor($user, $product);
        abort_unless(count($principalIds) === 1, 409, 'The product identity mapping needs explicit reconciliation.');

        return $projector->grant(
            $principalIds[0],
            (string) $workspace->getKey(),
            $role,
            $previous,
        );
    }

    /** @param array<string, array{managed?:bool,role?:string,created_by_projection?:bool,previous_role?:?string}> $projections */
    public function revoke(string $product, string $principalId, array $projections): int
    {
        $projector = $this->projectors->get($product);
        abort_if($projector === null, 404);

        return $projector->revoke($principalId, $projections);
    }
}
