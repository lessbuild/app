<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\AuditAction;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\AuditLogRequest;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\AuditStream;
use App\Models\BackupDestination;
use App\Models\User;
use App\Queries\Audit\AccountAuditLogQuery;
use App\Queries\Projects\ProjectSwitcherQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowAuditLogController
{
    /**
     * Show the account's audit log, narrowed by project, person, kind of change and dates.
     *
     * @param  Account  $account
     * @param  AuditLogRequest  $request
     * @param  User  $user
     * @param  AccountAuditLogQuery  $query
     * @param  ProjectSwitcherQuery  $projects
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, AuditLogRequest $request, #[CurrentUser] User $user, AccountAuditLogQuery $query, ProjectSwitcherQuery $projects): View
    {
        $projectOptions = $projects->handle($account, 500);
        $members = $account->members()->orderBy('name')->get(['users.id', 'users.name', 'users.email']);
        $filters = $request->filters(array_column($projectOptions, 'id'), $members->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all());

        return view('account.audit-log', [
            'account' => $account,
            'entries' => $query->handle($account, $filters)->withQueryString(),
            'filters' => $filters,
            'projects' => $projectOptions,
            'members' => $members,
            'categories' => AuditAction::CATEGORIES,
            'retentionDays' => AuditEntry::RETENTION_DAYS,
            'streams' => AuditStream::query()->where('account_id', $account->id)->with('destination')->orderBy('name')->get(),
            'backupDestinations' => BackupDestination::query()->where('account_id', $account->id)->orderBy('name')->get(['id', 'name']),
            'canManageStreams' => $user->can('update', $account),
        ]);
    }
}
