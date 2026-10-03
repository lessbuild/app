<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Account;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\Admin\FeatureFlags;
use App\Services\Admin\PlatformAdmins;
use Illuminate\Validation\ValidationException;

final class SaveFeatureFlag
{
    /**
     * Create a new SaveFeatureFlag instance.
     *
     * Creates and changes feature flags.
     *
     * @param  PlatformAdmins  $admins  Records the change in the admin trail.
     * @param  FeatureFlags  $flags  Forgets flags read earlier in this request.
     */
    public function __construct(private readonly PlatformAdmins $admins, private readonly FeatureFlags $flags) {}

    /**
     * Create a flag (off) or change one's description, state and accounts. Every account ID must exist.
     *
     * @param  User  $admin
     * @param  FeatureFlag|null  $flag  null to create
     * @param  array{key?: string, description: string, state?: string, account_ids?: list<string>}  $data
     * @return FeatureFlag
     */
    public function handle(User $admin, ?FeatureFlag $flag, array $data): FeatureFlag
    {
        $accounts = array_values(array_unique($data['account_ids'] ?? []));
        if (Account::query()->whereKey($accounts)->count() !== count($accounts)) {
            throw ValidationException::withMessages(['account_ids' => __('Every line must be an existing account ID.')]);
        }
        $record = $flag ?? new FeatureFlag;
        $record->forceFill([
            ...($flag === null ? ['key' => $data['key'] ?? '', 'state' => 'off'] : ['state' => $data['state'] ?? $flag->state]),
            'description' => $data['description'], 'account_ids' => $accounts === [] ? null : $accounts, 'updated_by' => $admin->id,
        ])->save();
        $this->flags->flush();
        $this->admins->record($admin, $flag === null ? 'flag.created' : 'flag.changed', $flag === null
            ? "Created the feature flag {$record->key}."
            : "Set the feature flag {$record->key} to {$record->state}".($record->state === 'accounts' ? ' for '.count($accounts).' accounts' : '').'.');

        return $record;
    }
}
