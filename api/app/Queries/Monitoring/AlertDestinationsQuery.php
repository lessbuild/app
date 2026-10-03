<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\AlertDestination;
use App\Models\Membership;
use App\Models\User;

final class AlertDestinationsQuery
{
    /**
     * Get the account's alert destinations with their recipient and how many monitors use each.
     *
     * @param  string  $accountId
     * @return list<AlertDestination>
     */
    public function handle(string $accountId): array
    {
        return array_values(AlertDestination::query()->where('account_id', $accountId)->with('recipient')
            ->withCount('monitors')->orderBy('name')->orderBy('id')->get()->all());
    }

    /**
     * Find one of the account's destinations; 404 otherwise. Archived ones only when asked for, so their history can
     * still be shown.
     *
     * @param  string  $accountId
     * @param  string|int  $id
     * @param  bool  $withArchived
     * @return AlertDestination
     */
    public function find(string $accountId, string|int $id, bool $withArchived = false): AlertDestination
    {
        $query = AlertDestination::query()->where('account_id', $accountId)->with('recipient');
        if ($withArchived) {
            $query->withTrashed();
        }

        return $query->whereKey((int) $id)->firstOrFail();
    }

    /**
     * Get the members with a verified email, who can be chosen as an email destination's recipient.
     *
     * @param  string  $accountId
     * @return list<User> verified members who can receive alert emails
     */
    public function recipients(string $accountId): array
    {
        return array_values(User::query()->whereNotNull('email_verified_at')
            ->whereIn('id', Membership::query()->where('account_id', $accountId)->select('user_id'))
            ->orderBy('name')->get()->all());
    }
}
