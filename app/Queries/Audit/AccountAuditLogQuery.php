<?php

declare(strict_types=1);

namespace App\Queries\Audit;

use App\Data\Audit\AuditEntryView;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Support\DeviceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;

final class AccountAuditLogQuery
{
    /**
     * Get the account's audit log, newest first and cursor-paginated, optionally narrowed to one project.
     *
     * @param  Account  $account
     * @param  string|null  $projectId
     * @param  int  $perPage
     * @return CursorPaginator<int, AuditEntryView>
     */
    public function handle(Account $account, ?string $projectId = null, int $perPage = 50): CursorPaginator
    {
        return AuditEntry::query()
            ->where('account_id', $account->id)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
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
