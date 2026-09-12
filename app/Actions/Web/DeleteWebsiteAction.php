<?php

namespace App\Actions\Web;

use App\Models\Website;
use Illuminate\Support\Facades\DB;

class DeleteWebsiteAction
{
    /**
     * Soft-delete a website only when no active deployment still reserves it.
     *
     * @param  Website  $website  Website supplying the row to lock and delete.
     * @return bool Whether the website was deleted; false means an active deployment still exists.
     */
    public function handle(Website $website): bool
    {
        return DB::transaction(function () use ($website): bool {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->hasActiveDeployment()) {
                return false;
            }

            return (bool) $locked->delete();
        });
    }
}
