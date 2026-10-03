<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveEnvironmentVariable
{
    /**
     * Set a variable (a new version if it exists). It reaches the server with the next deploy.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{key: string, value: string, is_secret: bool, scope: string, rotation_due_at?: string|null}  $data
     * @param  bool  $approved  whether a second person approved it, for environments that require that
     * @return EnvironmentVariable
     */
    public function handle(User $actor, Environment $environment, array $data, bool $approved = false): EnvironmentVariable
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if ($environment->require_variable_approval && ! $approved) {
            throw ValidationException::withMessages(['key' => __('Variable changes in this environment need someone else’s approval.')]);
        }

        return DB::transaction(function () use ($actor, $environment, $data): EnvironmentVariable {
            $variable = $environment->variables()->where('key', $data['key'])->lockForUpdate()->first();
            $version = ($variable->current_version ?? 0) + 1;
            $variable ??= new EnvironmentVariable;
            $variable->forceFill([
                'environment_id' => $environment->id, 'key' => $data['key'], 'value' => $data['value'], 'is_secret' => $data['is_secret'], 'scope' => $data['scope'],
                'current_version' => $version, 'rotated_at' => $version > 1 ? now() : null, 'rotation_due_at' => $data['rotation_due_at'] ?? null, 'updated_by' => $actor->id,
            ])->save();
            $variable->versions()->forceCreate(['created_by' => $actor->id, 'version' => $version, 'value' => $data['value']]);

            return $variable;
        });
    }
}
