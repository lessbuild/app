<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Account;
use App\Models\MaintenanceWindow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteMaintenanceWindow
{
    public function handle(Account $account, User $actor, MaintenanceWindow $window): void
    {
        DB::transaction(function () use ($account, $actor, $window): void {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('delete', $window);
            MaintenanceWindow::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($window->id)->delete();
        }, attempts: 3);
    }
}
