<?php

namespace App\Core\Services;

use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

final class WorkspaceProjectNavigation
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function directoryUrl(
        string $sourceProduct,
        string $sourceEntity,
        string|int $sourceWorkspaceId,
        ?string $fallbackUrl = null,
    ): ?string {
        if (! Route::has('core.projects.index')) {
            return $fallbackUrl;
        }

        try {
            $workspaceId = $this->identities->canonicalIdForSource(
                product: $sourceProduct,
                sourceEntity: $sourceEntity,
                sourceId: $sourceWorkspaceId,
                canonicalEntity: 'workspace',
            );
        } catch (ConnectionException|QueryException) {
            return $fallbackUrl;
        }

        return $workspaceId !== null
            ? route('core.projects.index', $workspaceId)
            : $fallbackUrl;
    }
}
