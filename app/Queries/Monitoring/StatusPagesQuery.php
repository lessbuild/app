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
    /** @return Collection<int, StatusPage> */
    public function handle(string $accountId): Collection
    {
        return StatusPage::query()->where('account_id', $accountId)
            ->withCount(['components', 'subscriptions' => fn ($query) => $query->whereNotNull('verified_at')])
            ->orderBy('name')->orderBy('id')->get();
    }

    public function find(string $accountId, int|string $id): StatusPage
    {
        return StatusPage::query()->where('account_id', $accountId)->with('components.monitor.environment.project')->findOrFail((int) $id);
    }

    /** @return Collection<int, Monitor> */
    public function monitors(Account $account): Collection
    {
        return Monitor::query()->forAccount($account)->with('environment.project')->orderBy('name')->orderBy('id')->get();
    }
}
