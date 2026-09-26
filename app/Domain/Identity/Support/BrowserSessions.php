<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The user's rows in the database session store. Only available when sessions live in the database. */
final class BrowserSessions
{
    public function __construct(private readonly Repository $config) {}

    public function available(): bool
    {
        return $this->config->get('session.driver') === 'database';
    }

    public function for(User $user): Builder
    {
        return DB::table((string) $this->config->get('session.table', 'sessions'))->where('user_id', $user->id);
    }
}
