<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SetServerMonthlyCost
{
    /** Enter what an imported server costs (its provider isn't one we can ask); null forgets it. */
    public function handle(User $actor, Server $server, ?float $amount, string $currency): void
    {
        Gate::forUser($actor)->authorize('update', $server);
        if ($server->provider_id !== null) {
            throw ValidationException::withMessages(['monthly_cost' => __('This server’s cost comes from its provider.')]);
        }
        $server->forceFill([
            'monthly_cost' => $amount === null ? null : round($amount, 2), 'monthly_cost_currency' => $amount === null ? null : $currency,
            'monthly_cost_source' => $amount === null ? null : 'manual', 'monthly_cost_checked_at' => $amount === null ? null : now(),
        ])->save();
    }
}
