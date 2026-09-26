<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Queries\AccountAuditLogQuery;
use App\Domain\Identity\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class AuditLogController
{
    public function __invoke(#[CurrentUser] User $user, AccountAuditLogQuery $query): View
    {
        $account = $user->currentAccount ?? abort(404);
        Gate::authorize('viewAuditLog', $account);

        return view('account.audit-log', [
            'account' => $account,
            'entries' => $query->handle($account),
            'retentionDays' => AuditEntry::RETENTION_DAYS,
        ]);
    }
}
