<?php

declare(strict_types=1);

namespace App\Queries\Audit;

use App\Data\Audit\AuditEntryView;
use App\Data\Audit\AuditLogFilters;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Support\DeviceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

final class AccountAuditLogQuery
{
    /**
     * Get the account's audit log, newest first and cursor-paginated, narrowed by the filters.
     *
     * @param  Account  $account
     * @param  AuditLogFilters  $filters
     * @param  int  $perPage
     * @return CursorPaginator<int, AuditEntryView>
     */
    public function handle(Account $account, AuditLogFilters $filters = new AuditLogFilters, int $perPage = 50): CursorPaginator
    {
        return $this->entries($account, $filters)->cursorPaginate($perPage)->through(fn (AuditEntry $entry): AuditEntryView => $this->view($entry));
    }

    /**
     * Get every matching entry, newest first, a few hundred at a time, for exporting.
     *
     * @param  Account  $account
     * @param  AuditLogFilters  $filters
     * @return LazyCollection<int, AuditEntryView>
     */
    public function export(Account $account, AuditLogFilters $filters): LazyCollection
    {
        return $this->entries($account, $filters)->lazy(500)->map(fn (AuditEntry $entry): AuditEntryView => $this->view($entry));
    }

    /**
     * Query the account's entries matching the filters, newest first.
     *
     * @param  Account  $account
     * @param  AuditLogFilters  $filters
     * @return Builder<AuditEntry>
     */
    private function entries(Account $account, AuditLogFilters $filters): Builder
    {
        return AuditEntry::query()
            ->where('account_id', $account->id)
            ->when($filters->projectId !== null, fn ($query) => $query->where('project_id', $filters->projectId))
            ->when($filters->actorId !== null, fn ($query) => $query->where('actor_id', $filters->actorId))
            ->when($filters->category !== null, fn ($query) => $query->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, AuditAction::inCategory((string) $filters->category))))
            ->when($filters->from !== null, fn ($query) => $query->where('created_at', '>=', $filters->from?->startOfDay()))
            ->when($filters->to !== null, fn ($query) => $query->where('created_at', '<=', $filters->to?->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Turn an entry into what the log shows.
     *
     * @param  AuditEntry  $entry
     * @return AuditEntryView
     */
    private function view(AuditEntry $entry): AuditEntryView
    {
        return new AuditEntryView(
            id: $entry->id,
            actor: $entry->actor_name ?? __('System'),
            actorEmail: $entry->actor_email,
            description: $entry->action->describe($entry->context ?? []),
            ipAddress: $entry->ip_address,
            device: $entry->user_agent !== null ? DeviceLabel::from($entry->user_agent) : null,
            at: CarbonImmutable::instance($entry->created_at),
            category: $entry->action->category(),
        );
    }
}
