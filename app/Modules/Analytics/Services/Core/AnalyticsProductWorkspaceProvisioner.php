<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProductWorkspaceProvisioner;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AnalyticsProductWorkspaceProvisioner implements ProductWorkspaceProvisioner
{
    public function ensure(string $ownerProductPrincipalId, CoreWorkspace $coreWorkspace, ?string $mappedProductWorkspaceId = null): string
    {
        if ($mappedProductWorkspaceId !== null) {
            return (string) Workspace::query()->findOrFail($mappedProductWorkspaceId)->getKey();
        }

        return DB::connection('analytics')->transaction(function () use ($ownerProductPrincipalId, $coreWorkspace): string {
            $owner = User::query()->findOrFail($ownerProductPrincipalId);
            $slug = $this->projectionSlug((string) $coreWorkspace->getKey());
            $workspace = Workspace::query()->where('slug', $slug)->lockForUpdate()->first();

            if ($workspace === null) {
                $workspace = Workspace::query()->create([
                    'name' => $coreWorkspace->name,
                    'slug' => $slug,
                ]);
            } elseif ($workspace->name !== $coreWorkspace->name) {
                $workspace->update(['name' => $coreWorkspace->name]);
            }

            $workspace->users()->syncWithoutDetaching([
                $owner->getKey() => ['role' => WorkspaceRole::Owner->value],
            ]);

            return (string) $workspace->getKey();
        }, attempts: 3);
    }

    private function projectionSlug(string $coreWorkspaceId): string
    {
        return 'core-'.Str::lower(Str::slug($coreWorkspaceId));
    }
}
