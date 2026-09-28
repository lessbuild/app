<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\User;
use App\Queries\Audit\AccountAuditLogQuery;
use App\Queries\Projects\ProjectSwitcherQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowAuditLogController
{
    /**
     * Show the account's audit log, optionally narrowed to one project.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  AccountAuditLogQuery  $query
     * @param  ProjectSwitcherQuery  $projects
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, AccountAuditLogQuery $query, ProjectSwitcherQuery $projects): View
    {
        $projectOptions = $projects->handle($account, 500);
        $projectId = $request->string('project')->toString();
        $projectId = in_array($projectId, array_column($projectOptions, 'id'), true) ? $projectId : null;

        return view('account.audit-log', [
            'account' => $account,
            'entries' => $query->handle($account, $projectId)->withQueryString(),
            'projects' => $projectOptions,
            'projectId' => $projectId,
            'retentionDays' => AuditEntry::RETENTION_DAYS,
        ]);
    }
}
