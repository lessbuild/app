<?php

namespace App\Actions\Database;

use App\Jobs\Database\ManageDatabaseUserJob;
use App\Models\DatabaseUser;

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
