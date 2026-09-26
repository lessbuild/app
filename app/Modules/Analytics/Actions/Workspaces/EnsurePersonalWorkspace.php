<?php

namespace App\Modules\Analytics\Actions\Workspaces;

use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

final class EnsurePersonalWorkspace
{
    public function __construct(
        private readonly AnalyticsWorkspaceAccess $access,
        private readonly ProductAuthentication $authentication,
    ) {}

    public function handle(Authenticatable $user, int|string|null $siteId = null): Workspace
    {
        $workspaces = $this->access->workspacesFor($user);

        if ($siteId !== null) {
            $site = Site::query()->find($siteId);
            $siteWorkspace = $site === null ? null : $workspaces->firstWhere('id', (int) $site->workspace_id);

            if ($siteWorkspace !== null) {
                abort_unless($this->access->hasSiteAccess($user, $site), 403);

                return $siteWorkspace;
            }
        }

        $selectedWorkspaceId = session()->get('analytics_workspace_id');
        $workspace = $workspaces->firstWhere('id', (int) $selectedWorkspaceId) ?? $workspaces->first();

        if ($workspace) {
            session()->put('analytics_workspace_id', $workspace->id);

            return $workspace;
        }

        $productUserIds = $this->access->productUserIds($user);
        abort_if($productUserIds === [], 403, 'Analytics access is not yet reconciled for this account.');
        foreach ($productUserIds as $productUserId) {
            app(AnalyticsDeletionFence::class)->assertAccountOpen($productUserId);
        }
        abort_if(
            $this->authentication->usesCoreAuthority('analytics'),
            409,
            'This account does not have an Analytics workspace. Create or join a shared workspace in Buildpusher Core.',
        );

        $name = (string) (data_get($user, 'name') ?: 'My');
        $workspace = Workspace::create([
            'name' => Str::of($name)->trim()->append("'s workspace")->toString(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        ]);

        $workspace->users()->attach(collect($productUserIds)->mapWithKeys(
            fn (string $productUserId): array => [$productUserId => ['role' => WorkspaceRole::Owner->value]],
        )->all());
        session()->put('analytics_workspace_id', $workspace->id);

        return $workspace;
    }
}
