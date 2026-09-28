<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateDeploymentControls
{
    /**
     * Lock an environment against deploys (with a reason), or limit deploys to a weekly window. Pushes that arrive while
     * blocked wait and deploy once allowed.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{locked: bool, lock_reason: string|null, window: bool, days: list<int>, start: string|null, end: string|null, timezone: string|null}  $data
     * @return void
     */
    public function handle(User $actor, Environment $environment, array $data): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $environment->forceFill([
            'deployment_locked_at' => $data['locked'] ? ($environment->deployment_locked_at ?? now()) : null,
            'deployment_locked_by' => $data['locked'] ? ($environment->deployment_locked_by ?? $actor->id) : null,
            'deployment_lock_reason' => $data['locked'] ? (trim((string) $data['lock_reason']) ?: null) : null,
            'deployment_window_days' => $data['window'] ? array_values(array_unique($data['days'])) : null,
            'deployment_window_start' => $data['window'] ? $data['start'] : null,
            'deployment_window_end' => $data['window'] ? $data['end'] : null,
            'deployment_window_timezone' => $data['window'] ? ($data['timezone'] ?: 'UTC') : null,
        ])->save();
    }
}
