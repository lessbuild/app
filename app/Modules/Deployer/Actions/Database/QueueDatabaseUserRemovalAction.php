<?php

namespace App\Modules\Deployer\Actions\Database;

use App\Modules\Deployer\Jobs\Database\ManageDatabaseUserJob;
use App\Modules\Deployer\Models\DatabaseUser;

class QueueDatabaseUserRemovalAction
{
    /**
     * Queue remote removal for an already-authorized database credential.
     */
    public function handle(DatabaseUser $databaseUser): void
    {
        ManageDatabaseUserJob::dispatch($databaseUser->id, 'remove');
    }
}
