<?php

namespace App\Modules\Analytics\Actions\Workspaces;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

final class EnsurePersonalWorkspace
{
    public function __construct(private readonly AnalyticsWorkspaceAccess $access) {}

    public function handle(Authenticatable $user): Workspace
    {
        $workspaces = $this->access->workspacesFor($user);
        $selectedWorkspaceId = session()->get('analytics_workspace_id');
        $workspace = $workspaces->firstWhere('id', (int) $selectedWorkspaceId) ?? $workspaces->first();

        if ($workspace) {
            session()->put('analytics_workspace_id', $workspace->id);

            return $workspace;
        }

        $productUserIds = $this->access->productUserIds($user);
        abort_if($productUserIds === [], 403, 'Analytics access is not yet reconciled for this account.');

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
