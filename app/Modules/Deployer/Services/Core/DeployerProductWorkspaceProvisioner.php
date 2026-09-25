<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProductWorkspaceProvisioner;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeployerProductWorkspaceProvisioner implements ProductWorkspaceProvisioner
{
    public function ensure(string $ownerProductPrincipalId, CoreWorkspace $coreWorkspace, ?string $mappedProductWorkspaceId = null): string
    {
        if ($mappedProductWorkspaceId !== null) {
            return (string) Organization::query()->findOrFail($mappedProductWorkspaceId)->getKey();
        }

        return DB::connection('deployer')->transaction(function () use ($ownerProductPrincipalId, $coreWorkspace): string {
            $owner = User::query()->findOrFail($ownerProductPrincipalId);
            $slug = $this->projectionSlug((string) $coreWorkspace->getKey());
            $organization = Organization::query()->where('slug', $slug)->lockForUpdate()->first();

            if ($organization === null) {
                $organization = Organization::query()->create([
                    'owner_id' => $owner->getKey(),
                    'name' => $coreWorkspace->name,
                    'slug' => $slug,
                ]);
                $organization->members()->attach($owner->getKey(), ['role' => 'owner']);
            } else {
                abort_unless((string) $organization->owner_id === (string) $owner->getKey(), 409, 'A Deployer workspace projection conflicts with this Core workspace.');
                if ($organization->name !== $coreWorkspace->name) {
                    $organization->update(['name' => $coreWorkspace->name]);
                }
                $organization->members()->syncWithoutDetaching([$owner->getKey() => ['role' => 'owner']]);
            }

            if ($owner->current_organization_id === null) {
                $owner->forceFill(['current_organization_id' => $organization->getKey()])->save();
                $owner->setRelation('currentOrganization', $organization);
            }

            return (string) $organization->getKey();
        }, attempts: 3);
    }

    private function projectionSlug(string $coreWorkspaceId): string
    {
        return 'core-'.Str::lower(Str::slug($coreWorkspaceId));
    }
}
