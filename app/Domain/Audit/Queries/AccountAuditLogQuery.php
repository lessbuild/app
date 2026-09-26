<?php

declare(strict_types=1);

namespace App\Domain\Audit\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Data\AuditEntryView;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Support\DeviceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;

final class AccountAuditLogQuery
{
    /** @return CursorPaginator<int, AuditEntryView> */
    public function handle(Account $account, int $perPage = 50): CursorPaginator
    {
        return AuditEntry::query()
            ->where('account_id', $account->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->through(fn (AuditEntry $entry): AuditEntryView => new AuditEntryView(
                id: $entry->id,
                actor: $entry->actor_name ?? __('System'),
                actorEmail: $entry->actor_email,
                description: $entry->action->describe($entry->context ?? []),
                ipAddress: $entry->ip_address,
                device: $entry->user_agent !== null ? DeviceLabel::from($entry->user_agent) : null,
                at: CarbonImmutable::instance($entry->created_at),
            ));
    }
}
