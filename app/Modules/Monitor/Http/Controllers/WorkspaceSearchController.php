<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Core\Services\Search\ProductWorkspaceSearch;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceSearchController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        ProductWorkspaceSearch $search,
    ): JsonResponse {
        $principal = $request->user();
        abort_unless($principal !== null, 401);
        abort_unless(
            $principal->workspaces()->whereKey($workspace->getKey())->exists(),
            404,
        );

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = (string) ($validated['q'] ?? '');
        $results = $search->fromSourceWorkspace(
            principal: $principal,
            product: 'monitor',
            sourceEntity: 'workspace',
            sourceWorkspaceId: $workspace->getKey(),
            query: $query,
        );

        return response()->json($results ?? [
            'query' => mb_substr(trim($query), 0, 100),
            'groups' => [],
            'unavailable' => [],
        ])->header('Cache-Control', 'private, no-store');
    }
}
