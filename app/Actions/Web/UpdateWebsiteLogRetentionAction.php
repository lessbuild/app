<?php

namespace App\Actions\Web;

use App\Models\Website;

class UpdateWebsiteLogRetentionAction
{
    /**
     * Persist the validated trailing-line count used by future runtime-log snapshots.
     *
     * @param  Website  $website  Website whose log retention setting is updated.
     * @param  int  $lines  Validated supported trailing-line count.
     */
    public function handle(Website $website, int $lines): void
    {
        $website->update(['log_retention_lines' => $lines]);
    }
}
