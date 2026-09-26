<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProductWorkspaceProvisioner;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AnalyticsProductWorkspaceProvisioner implements ProductWorkspaceProvisioner
{
    public function ensure(string $ownerProductPrincipalId, CoreWorkspace $coreWorkspace, ?string $mappedProductWorkspaceId = null): string
    {
        app(AnalyticsDeletionFence::class)->assertCanonicalWorkspaceOpen((string) $coreWorkspace->getKey());
        app(AnalyticsDeletionFence::class)->assertCanonicalAccountOpen((string) $coreWorkspace->owner_user_id);

        if ($mappedProductWorkspaceId !== null) {
            $workspace = Workspace::query()->findOrFail($mappedProductWorkspaceId);
            app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($workspace->getKey());

            return (string) $workspace->getKey();
        }

        return DB::connection('analytics')->transaction(function () use ($ownerProductPrincipalId, $coreWorkspace): string {
            $owner = User::query()->findOrFail($ownerProductPrincipalId);
            app(AnalyticsDeletionFence::class)->assertAccountOpen($owner->getKey());
            $slug = $this->projectionSlug((string) $coreWorkspace->getKey());
            $workspace = Workspace::query()->where('slug', $slug)->lockForUpdate()->first();

            if ($workspace !== null) {
                app(AnalyticsDeletionFence::class)->assertWorkspaceOpen($workspace->getKey());
            }

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
