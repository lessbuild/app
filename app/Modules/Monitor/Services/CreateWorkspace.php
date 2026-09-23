<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateWorkspace
{
    public function create(User $owner, string $name): Workspace
    {
        return DB::connection('monitor')->transaction(function () use ($owner, $name): Workspace {
            $workspace = new Workspace(['name' => $name, 'slug' => Str::slug($name).'-'.Str::uuid()]);
            $workspace->owner()->associate($owner);
            $workspace->save();
            $workspace->members()->attach($owner, ['role' => 'owner']);

            return $workspace;
        });
    }
}
