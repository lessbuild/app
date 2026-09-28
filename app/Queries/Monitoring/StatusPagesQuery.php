<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Account;
use App\Models\Monitor;
use App\Models\StatusPage;
use Illuminate\Database\Eloquent\Collection;

/** An account's status pages, and the monitors a page can show. */
final class StatusPagesQuery
{
    /**
     * The account's status pages with their component and confirmed-subscriber counts.
     *
     * @param  string  $accountId
     * @return Collection<int, StatusPage>
     */
    public function handle(string $accountId): Collection
    {
        return StatusPage::query()->where('account_id', $accountId)
            ->withCount(['components', 'subscriptions' => fn ($query) => $query->whereNotNull('verified_at')])
            ->orderBy('name')->orderBy('id')->get();
    }

    /**
     * One of the account's status pages with its components; 404 otherwise.
     *
     * @param  string  $accountId
     * @param  string|int  $id
     * @return StatusPage
     */
    public function find(string $accountId, int|string $id): StatusPage
    {
        return StatusPage::query()->where('account_id', $accountId)->with('components.monitor.environment.project')->findOrFail((int) $id);
    }

    /**
     * The account's monitors, which can be shown as components.
     *
     * @param  Account  $account
     * @return Collection<int, Monitor>
     */
    public function monitors(Account $account): Collection
    {
        return Monitor::query()->forAccount($account)->with('environment.project')->orderBy('name')->orderBy('id')->get();
    }
}
