<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\Search\WorkspaceSearch;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceSearchController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        WorkspaceSearch $search,
    ): JsonResponse {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if(! $user instanceof PlatformUser, 403);
        abort_if($access->activeMembership($user, $workspace) === null, 404);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $results = $search->forWorkspace($user, $workspace, (string) ($validated['q'] ?? ''));

        return response()->json($results)->header('Cache-Control', 'private, no-store');
    }
}
