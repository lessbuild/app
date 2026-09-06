<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationConfigurationVariables
{
    /** Internal reconciler primitive; adoption and review freshness are checked by the caller. */
    public function synchronize(Environment $environment, string $key, int $sourceId, int $sourceVersion, string $scope, User $user): EnvironmentVariable
    {
        return DB::transaction(function () use ($environment, $key, $sourceId, $sourceVersion, $scope, $user) {
            $environment = Environment::query()->findOrFail($environment->id);
            $organization = $environment->project->organization;
            $user = User::query()->findOrFail($user->id);
            if ((int) $user->current_organization_id !== (int) $organization->id || ! $organization->permits($user, 'manage')) {
                throw new AuthorizationException;
            }
            if (! preg_match('/\A[A-Z_][A-Z0-9_]{0,99}\z/', $key) || ! in_array($scope, EnvironmentVariable::SCOPES, true)) {
                throw ValidationException::withMessages(['bindings' => 'Invalid variable target.']);
            }
            $source = EnvironmentVariable::query()->whereKey($sourceId)->where('is_secret', true)
                ->whereHas('environment.project', fn ($query) => $query->where('organization_id', $organization->id))
                ->lockForUpdate()->first();
            if (! $source || $source->current_version !== $sourceVersion
                || ($source->scope !== 'all' && $source->scope !== $scope)) {
                throw ValidationException::withMessages(['bindings' => 'The reviewed secret binding is no longer available.']);
            }
            $target = $environment->variables()->where('key', $key)->lockForUpdate()->first();
            $value = $source->value;
            if ($target && $target->value === $value && $target->is_secret && $target->scope === $scope) {
                return $target;
            }
            $version = ($target?->current_version ?? 0) + 1;
            $attributes = ['value' => $value, 'is_secret' => true, 'scope' => $scope,
                'current_version' => $version, 'updated_by' => $user->id,
                'rotated_at' => $target ? now() : null];
            if ($target) {
                $target->update($attributes);
            } else {
                $target = $environment->variables()->create(['key' => $key, ...$attributes]);
            }
            $target->versions()->create(['version' => $version, 'value' => $value, 'created_by' => $user->id]);

            return $target;
        });
    }
}
