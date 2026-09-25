<?php

namespace App\Core\Services\Workspaces;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreatePlatformWorkspace
{
    public function handle(PlatformUser $owner, string $name): Workspace
    {
        return DB::connection('core')->transaction(function () use ($owner, $name): Workspace {
            $lockedOwner = PlatformUser::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($lockedOwner->status === 'active' && $lockedOwner->hasVerifiedEmail(), 403);

            $name = trim($name);
            abort_if($name === '', 422, 'Enter a workspace name.');

            $slugBase = Str::limit(Str::slug($name) ?: 'workspace', 100, '');
            do {
                $slug = $slugBase.'-'.Str::lower(Str::random(8));
            } while (Workspace::query()->where('slug', $slug)->exists());

            $workspace = Workspace::query()->create([
                'owner_user_id' => $lockedOwner->getKey(),
                'name' => $name,
                'slug' => $slug,
                'status' => 'active',
            ]);

            $workspace->memberships()->create([
                'user_id' => $lockedOwner->getKey(),
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return $workspace;
        }, attempts: 3);
    }
}
