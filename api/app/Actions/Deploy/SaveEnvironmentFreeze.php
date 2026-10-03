<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\EnvironmentFreeze;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveEnvironmentFreeze
{
    /**
     * Freeze deploys to an environment for a period that ends in the future and lasts at most 90 days.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  CarbonImmutable  $startsAt
     * @param  CarbonImmutable  $endsAt
     * @param  string|null  $reason
     * @return EnvironmentFreeze
     */
    public function handle(User $actor, Environment $environment, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?string $reason): EnvironmentFreeze
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if ($endsAt->lessThanOrEqualTo($startsAt) || $endsAt->isPast()) {
            throw ValidationException::withMessages(['ends_at' => __('The freeze must end after it starts, and in the future.')]);
        }
        if ($startsAt->diffInDays($endsAt) > 90) {
            throw ValidationException::withMessages(['ends_at' => __('A freeze can last up to 90 days.')]);
        }
        $freeze = new EnvironmentFreeze;
        $freeze->forceFill([
            'environment_id' => $environment->id, 'starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null, 'created_by' => $actor->id,
        ])->save();

        return $freeze;
    }
}
