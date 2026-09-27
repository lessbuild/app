<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Account;
use App\Models\MaintenanceWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveMaintenanceWindow
{
    /**
     * Create or change a maintenance window. Monitors in the account don't open incidents or send alerts during one.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Account $account, User $actor, array $data, ?MaintenanceWindow $window = null): MaintenanceWindow
    {
        return DB::transaction(function () use ($account, $actor, $data, $window): MaintenanceWindow {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize($window === null ? 'create' : 'update', $window ?? [MaintenanceWindow::class, $account]);
            $maintenanceWindow = $window === null
                ? new MaintenanceWindow(['account_id' => $account->id, 'created_by' => $actor->id])
                : MaintenanceWindow::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($window->id);
            $startsAt = $this->parse($data['starts_at'] ?? null);
            $endsAt = $this->parse($data['ends_at'] ?? null);

            if ($startsAt === null || $endsAt === null || ! $endsAt->greaterThan($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => __('The maintenance window must end after it starts.')]);
            }

            $maintenanceWindow->forceFill([
                'account_id' => $account->id,
                'created_by' => $maintenanceWindow->created_by ?? $actor->id,
                'name' => trim((string) $data['name']),
                'reason' => isset($data['reason']) && trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ])->save();

            return $maintenanceWindow;
        }, attempts: 3);
    }

    /**
     * A time from the form's `datetime-local` input, read as UTC, or null when it's empty or malformed.
     */
    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', trim($value), 'UTC');

        return $parsed instanceof CarbonImmutable ? $parsed : null;
    }
}
