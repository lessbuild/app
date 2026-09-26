<?php

namespace App\Modules\Deployer\Actions\Backup;

use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Models\WebsiteBackupSchedule;

class SaveBackupScheduleAction
{
    /**
     * Save one workspace-owned website schedule while retaining its active state.
     *
     * @param  array<string, mixed>  $attributes  Validated frequency, timing, and retention attributes.
     */
    public function handle(Website $website, BackupDestination $destination, array $attributes): WebsiteBackupSchedule
    {
        return WebsiteBackupSchedule::query()->updateOrCreate([
            'website_id' => $website->id,
            'backup_destination_id' => $destination->id,
        ], [
            ...$attributes,
            'website_id' => $website->id,
            'backup_destination_id' => $destination->id,
            'is_active' => true,
        ]);
    }
}
