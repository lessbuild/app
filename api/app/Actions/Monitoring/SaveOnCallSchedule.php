<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Account;
use App\Models\OnCallSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveOnCallSchedule
{
    /**
     * Create or update an on-call schedule: its rotation and the verified members who take turns, in order.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  OnCallSchedule|null  $schedule  null to create one
     * @param  array{name: string, timezone: string, rotation: string, handoff_time: string, handoff_day: int|null, starts_on: string, member_ids: list<string>}  $data
     * @return OnCallSchedule
     */
    public function handle(User $actor, Account $account, ?OnCallSchedule $schedule, array $data): OnCallSchedule
    {
        Gate::forUser($actor)->authorize($schedule === null ? 'create' : 'update', $schedule ?? [OnCallSchedule::class, $account]);
        $members = array_values(array_unique($data['member_ids']));
        $valid = $account->members()->whereNotNull('email_verified_at')->whereIn('users.id', $members)->pluck('users.id')->all();
        if ($members === [] || count($valid) !== count($members)) {
            throw ValidationException::withMessages(['member_ids' => __('Choose one or more verified members of this account.')]);
        }

        return DB::transaction(function () use ($account, $schedule, $data, $members): OnCallSchedule {
            $schedule ??= new OnCallSchedule;
            $schedule->forceFill([
                'account_id' => $account->id, 'name' => trim($data['name']), 'timezone' => $data['timezone'],
                'rotation' => $data['rotation'] === 'weekly' ? 'weekly' : 'daily', 'handoff_time' => $data['handoff_time'],
                'handoff_day' => $data['rotation'] === 'weekly' ? ($data['handoff_day'] ?? 1) : null, 'starts_on' => $data['starts_on'],
            ])->save();
            $schedule->members()->sync(array_combine($members, array_map(fn (int $position): array => ['position' => $position], array_keys($members))));

            return $schedule;
        });
    }
}
