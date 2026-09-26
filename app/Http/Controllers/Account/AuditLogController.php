<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Queries\AccountAuditLogQuery;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Queries\ProjectSwitcherQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AuditLogController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, AccountAuditLogQuery $query, ProjectSwitcherQuery $projects): View
    {
        $account = $user->currentAccount ?? abort(404);
        Gate::authorize('viewAuditLog', $account);
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
