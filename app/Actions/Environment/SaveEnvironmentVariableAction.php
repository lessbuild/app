<?php

namespace App\Actions\Environment;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveEnvironmentVariableAction
{
    /**
     * Save an environment variable and append its encrypted version while holding the key row lock.
     *
     * @param  array{key: string, value: string, scope: string, rotation_due_at?: string|null}  $data
     */
    public function handle(Environment $environment, User $user, array $data, bool $isSecret): EnvironmentVariable
    {
        return DB::transaction(function () use ($environment, $user, $data, $isSecret): EnvironmentVariable {
            $variable = $environment->variables()->where('key', $data['key'])->lockForUpdate()->first();
            $version = ($variable?->current_version ?? 0) + 1;
            $attributes = [
                'value' => $data['value'],
                'is_secret' => $isSecret,
                'scope' => $data['scope'],
                'current_version' => $version,
                'rotated_at' => $variable ? now() : null,
                'rotation_due_at' => $data['rotation_due_at'] ?? null,
                'updated_by' => $user->id,
            ];
            if ($variable) {
                $variable->update($attributes);
            } else {
                $variable = $environment->variables()->create(['key' => $data['key'], ...$attributes]);
            }
            $variable->versions()->create([
                'created_by' => $user->id,
                'version' => $version,
                'value' => $data['value'],
            ]);

            return $variable;
        });
    }
}
