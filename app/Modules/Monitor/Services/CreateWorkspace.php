<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateWorkspace
{
    public function create(User $owner, string $name): Workspace
    {
        MonitorDeletionFence::assertUserActive($owner->getKey());

        return DB::connection('monitor')->transaction(function () use ($owner, $name): Workspace {
            MonitorDeletionFence::assertUserActive($owner->getKey());
            $workspace = new Workspace(['name' => $name, 'slug' => Str::slug($name).'-'.Str::uuid()]);
            $workspace->owner()->associate($owner);
            $workspace->save();
            $workspace->members()->attach($owner, ['role' => 'owner']);

            return $workspace;
        });
    }
}
