<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteDashboard
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function handle(Account $account, User $actor, Dashboard $dashboard): void
    {
        DB::transaction(function () use ($account, $actor, $dashboard): void {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('update', $account);
            $dashboard = Dashboard::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($dashboard->id);
            $dashboard->delete();
            $this->audit->handle(AuditAction::DashboardDeleted, $actor, $account->id, ['dashboard' => $dashboard->name]);
        }, attempts: 3);
    }
}
