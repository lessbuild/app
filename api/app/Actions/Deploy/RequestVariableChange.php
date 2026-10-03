<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\PendingVariableChange;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RequestVariableChange
{
    /**
     * Hold a variable change for approval: saving one ({key, value, is_secret, scope, rotation_due_at}), replacing
     * them all ({contents}) or removing one ({variable_id, key}).
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  string  $kind  save, replace or delete
     * @param  array<string, mixed>  $payload
     * @return PendingVariableChange
     */
    public function handle(User $actor, Environment $environment, string $kind, array $payload): PendingVariableChange
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $summary = match ($kind) {
            'save' => __('Set :key', ['key' => $payload['key'] ?? '?']),
            'delete' => __('Remove :key', ['key' => $payload['key'] ?? '?']),
            default => __('Replace all variables (:count lines)', ['count' => count(array_filter(preg_split('/\R/', (string) ($payload['contents'] ?? '')) ?: [], fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), '#')))]),
        };
        $change = new PendingVariableChange;
        $change->forceFill(['environment_id' => $environment->id, 'kind' => $kind, 'payload' => $payload, 'summary' => $summary, 'status' => 'pending', 'requested_by' => $actor->id])->save();

        return $change;
    }
}
