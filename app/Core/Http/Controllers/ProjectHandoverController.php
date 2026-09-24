<?php

namespace App\Core\Http\Controllers;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Http\Requests\StoreProjectHandoverManifestRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\Connections\ProjectConnectionEntitlementPolicy;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ProjectResourceDestinations;
use App\Core\Services\Projects\ExportProjectHandoverManifest;
use App\Core\Services\Projects\ProjectHandoverManifestParser;
use App\Core\Services\Projects\ValidateProjectHandoverManifest;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProjectHandoverController
{
    public function form(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        abort_unless($access->canManageWorkspace($user, $workspace), 403);

        return view('core.projects.handover-form', $this->shellData($user, $workspace));
    }

    public function validateManifest(
        StoreProjectHandoverManifestRequest $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        ValidateProjectHandoverManifest $validator,
        ProductPlanResolver $plans,
        ProjectResourceDestinationRegistry $providerRegistry,
        ProjectResourceDestinations $destinations,
        ProjectConnectionEntitlementPolicy $connectionEntitlements,
        ProjectHandoverManifestParser $parser,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        abort_unless($access->canManageWorkspace($user, $workspace), 403);

        $report = $validator->handle(
            user: $user,
            workspace: $workspace,
            contents: $request->file('manifest')->get(),
            access: $access,
            plans: $plans,
            providerRegistry: $providerRegistry,
            destinations: $destinations,
            connectionEntitlements: $connectionEntitlements,
            parser: $parser,
        );

        return view('core.projects.handover-report', $this->shellData($user, $workspace) + ['report' => $report]);
    }

    public function export(
        Request $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        ExportProjectHandoverManifest $exporter,
        ProjectResourceDestinations $destinations,
    ): StreamedResponse {
        $user = $this->platformUser($request, $platformUsers);
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        abort_unless($access->canManageWorkspace($user, $workspace), 403);

        $manifest = $exporter->handle($user, $workspace, $project, $access, $destinations);
        $filename = 'buildpusher-'.Str::limit(Str::slug($project->slug) ?: 'project', 100, '').'-handover-v'.ExportProjectHandoverManifest::VERSION.'.json';

        return response()->streamDownload(
            static function () use ($manifest): void {
                echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            },
            $filename,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /** @return array{user:PlatformUser,workspace:Workspace,workspaces:array<int,Workspace>,contextProjects:array<mixed>} */
    private function shellData(PlatformUser $user, Workspace $workspace): array
    {
        return [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => [$workspace],
            'contextProjects' => [],
        ];
    }

    private function platformUser(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $platformUser = $platformUsers->resolve($principal, 'deployer');
        abort_if($platformUser === null, 403);

        return $platformUser;
    }
}
