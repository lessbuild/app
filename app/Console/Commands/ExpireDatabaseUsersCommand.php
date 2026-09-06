<?php

namespace App\Console\Commands;

use App\Jobs\Database\ManageDatabaseUserJob;
use App\Models\DatabaseUser;
use Illuminate\Console\Command;

class ExpireDatabaseUsersCommand extends Command
{
    protected $signature = 'buildpusher:database-users:expire';

    protected $description = 'Queue removal of expired temporary database users';

    public function handle(): int
    {
        DatabaseUser::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNotNull('applied_at')
            ->eachById(fn (DatabaseUser $user) => ManageDatabaseUserJob::dispatch($user->id, 'remove'));

        return self::SUCCESS;
    }
}
