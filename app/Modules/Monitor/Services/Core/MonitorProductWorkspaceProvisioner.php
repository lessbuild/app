<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProductWorkspaceProvisioner;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MonitorProductWorkspaceProvisioner implements ProductWorkspaceProvisioner
{
    public function ensure(string $ownerProductPrincipalId, CoreWorkspace $coreWorkspace, ?string $mappedProductWorkspaceId = null): string
    {
        abort_if(MonitorDeletionFence::canonicalWorkspaceIsFenced($coreWorkspace->getKey()), 410, 'This Monitor workspace is being deleted.');

        if ($mappedProductWorkspaceId !== null) {
            MonitorDeletionFence::assertWorkspaceActive($mappedProductWorkspaceId);

            return (string) Workspace::query()->findOrFail($mappedProductWorkspaceId)->getKey();
        }

        return DB::connection('monitor')->transaction(function () use ($ownerProductPrincipalId, $coreWorkspace): string {
            $owner = User::query()->findOrFail($ownerProductPrincipalId);
            $slug = $this->projectionSlug((string) $coreWorkspace->getKey());
            $workspace = Workspace::query()->where('slug', $slug)->lockForUpdate()->first();

            if ($workspace !== null) {
                MonitorDeletionFence::assertWorkspaceActive($workspace->getKey());
            }

            if ($workspace === null) {
                $workspace = new Workspace(['name' => $coreWorkspace->name, 'slug' => $slug]);
                $workspace->owner()->associate($owner);
                $workspace->save();
                $workspace->members()->attach($owner->getKey(), ['role' => 'owner']);
            } else {
                abort_unless((string) $workspace->owner_id === (string) $owner->getKey(), 409, 'A Monitor workspace projection conflicts with this Core workspace.');
                if ($workspace->name !== $coreWorkspace->name) {
                    $workspace->update(['name' => $coreWorkspace->name]);
                }
                $workspace->members()->syncWithoutDetaching([$owner->getKey() => ['role' => 'owner']]);
            }

            return (string) $workspace->getKey();
        }, attempts: 3);
    }

    private function projectionSlug(string $coreWorkspaceId): string
    {
        return 'core-'.Str::lower(Str::slug($coreWorkspaceId));
    }
}
