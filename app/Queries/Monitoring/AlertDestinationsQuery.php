<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\AlertDestination;
use App\Models\Membership;
use App\Models\User;

final class AlertDestinationsQuery
{
    /** @return list<AlertDestination> */
    public function handle(string $accountId): array
    {
        return array_values(AlertDestination::query()->where('account_id', $accountId)->with('recipient')
            ->withCount('monitors')->orderBy('name')->orderBy('id')->get()->all());
    }

    public function find(string $accountId, string|int $id, bool $withArchived = false): AlertDestination
    {
        $query = AlertDestination::query()->where('account_id', $accountId)->with('recipient');
        if ($withArchived) {
            $query->withTrashed();
        }

        return $query->whereKey((int) $id)->firstOrFail();
    }

    /** @return list<User> verified members who can receive alert emails */
    public function recipients(string $accountId): array
    {
        return array_values(User::query()->whereNotNull('email_verified_at')
            ->whereIn('id', Membership::query()->where('account_id', $accountId)->select('user_id'))
            ->orderBy('name')->get()->all());
    }
}
