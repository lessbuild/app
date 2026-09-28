<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The user's rows in the database session store. Only available when sessions live in the database. */
final class BrowserSessions
{
    /**
     * Reads browser sessions from the database session store.
     *
     * @param  Repository  $config  Which session driver and table are in use.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Whether sessions are in the database, the only store they can be listed from.
     */
    public function available(): bool
    {
        return $this->config->get('session.driver') === 'database';
    }

    /**
     * The person's session rows.
     */
    public function for(User $user): Builder
    {
        return DB::table((string) $this->config->get('session.table', 'sessions'))->where('user_id', $user->id);
    }
}
