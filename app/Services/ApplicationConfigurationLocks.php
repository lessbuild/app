<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ApplicationConfigurationLocks
{
    /** Call inside a transaction, before reading the state that will be changed. */
    public static function project(int $id): Project
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite ignores FOR UPDATE. Reserve its writer before taking a read
            // snapshot so competing applies wait instead of upgrading stale reads.
            DB::table('projects')->where('id', $id)->update(['id' => DB::raw('id')]);
        }

        return Project::query()->lockForUpdate()->findOrFail($id);
    }
}
