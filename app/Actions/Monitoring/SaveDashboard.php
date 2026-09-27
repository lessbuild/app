<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\Dashboard;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveDashboard
{
    public function __construct(private readonly Entitlements $entitlements, private readonly RecordAuditEntry $audit) {}

    /**
     * Create or change a dashboard and replace its widgets, in the order given. New dashboards count against `monitoring.dashboards.max`.
     *
     * @param  array<string, mixed>  $data  validated by DashboardRequest
     */
    public function handle(Account $account, User $actor, array $data, ?Dashboard $dashboard = null): Dashboard
    {
        return DB::transaction(function () use ($account, $actor, $data, $dashboard): Dashboard {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('update', $account);
            $isNew = $dashboard === null;
            if ($isNew) {
                $decision = $this->entitlements->for($account)->allows('monitoring.dashboards.max', Dashboard::query()->where('account_id', $account->id)->count() + 1);
                if (! $decision->allowed) {
                    throw ValidationException::withMessages(['plan' => trans_choice('Your Monitoring plan allows :count dashboard. Upgrade to add more.|Your Monitoring plan allows :count dashboards. Upgrade to add more.', (int) $decision->limit, ['count' => $decision->limit])]);
                }
                $dashboard = new Dashboard;
            } else {
                $dashboard = Dashboard::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($dashboard->id);
            }
            $types = array_values(array_unique(array_map('strval', is_array($data['widgets'] ?? null) ? $data['widgets'] : [])));
            $description = trim((string) ($data['description'] ?? ''));

            $dashboard->forceFill([
                'account_id' => $account->id,
                'created_by' => $dashboard->created_by ?? $actor->id,
                'name' => trim((string) $data['name']),
                'description' => $description !== '' ? $description : null,
                'range' => (string) $data['range'],
            ])->save();
            $dashboard->widgets()->delete();
            foreach ($types as $position => $type) {
                $dashboard->widgets()->create(['type' => $type, 'position' => $position]);
            }
            $this->audit->handle($isNew ? AuditAction::DashboardCreated : AuditAction::DashboardUpdated, $actor, $account->id, ['dashboard' => $dashboard->name, 'widgets' => implode(', ', $types)]);

            return $dashboard;
        }, attempts: 3);
    }
}
