<?php

namespace App\Core\Contracts;

interface ProductWorkspaceMembershipProjector
{
    /**
     * @param  array<string, array{managed?:bool,role?:string,created_by_projection?:bool,previous_role?:?string}>  $previous
     * @return array<string, array{managed:bool,role:string,created_by_projection:bool,previous_role:?string}>
     */
    public function grant(string $productPrincipalId, string $coreWorkspaceId, string $role, array $previous = []): array;

    /** @param array<string, array{managed?:bool,role?:string,created_by_projection?:bool,previous_role?:?string}> $projections */
    public function revoke(string $productPrincipalId, array $projections): int;
}
