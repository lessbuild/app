<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\EnableSlowLog;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Gate;

final class EnableSlowQueryLog
{
    /**
     * Turn on the website's database server's slow query log (queries over a second, to a table), then inspect the
     * database again. It covers every database on that server.
     *
     * @param  User  $actor
     * @param  Website  $website
     * @return void
     */
    public function handle(User $actor, Website $website): void
    {
        Gate::forUser($actor)->authorize('manageDatabase', $website);
        EnableSlowLog::dispatch($website->id, $actor->id)->afterCommit();
    }
}
