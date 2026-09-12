<?php

namespace App\Actions\Environment;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplaceEnvironmentVariablesAction
{
    /**
     * Parse KEY=value lines and replace an environment's encrypted variables atomically.
     *
     * Parsing remains outside the transaction, matching the existing API behavior: malformed
     * input cannot remove existing values, and validation messages never include submitted data.
     *
     * @param  Environment  $environment  Already-authorized environment.
     * @param  User  $actor  Account recorded on current and historical variable rows.
     * @param  string  $contents  Validated replacement text.
     * @return int Number of variables retained after replacement.
     *
     * @throws ValidationException If a non-comment line is not a KEY=value assignment.
     */
    public function handle(Environment $environment, User $actor, string $contents): int
    {
        $variables = $this->parse($contents);

        DB::transaction(function () use ($environment, $variables, $actor): void {
            $environment->variables()->whereNotIn('key', array_keys($variables))->delete();
            foreach ($variables as $key => $value) {
                $variable = $environment->variables()->where('key', $key)->lockForUpdate()->first();
                $version = ($variable?->current_version ?? 0) + 1;
                $attributes = [
                    'value' => $value,
                    'scope' => 'all',
                    'is_secret' => true,
                    'current_version' => $version,
                    'updated_by' => $actor->id,
                    'rotated_at' => $variable ? now() : null,
                ];
                if ($variable) {
                    $variable->update($attributes);
                } else {
                    $variable = $environment->variables()->create(['key' => $key, ...$attributes]);
                }
                $variable->versions()->create(['created_by' => $actor->id, 'version' => $version, 'value' => $value]);
            }
        });

        return count($variables);
    }

    /**
     * Parse only uppercase environment keys while keeping values opaque to errors and logs.
     *
     * @return array<string, string>
     */
    private function parse(string $contents): array
    {
        $variables = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (! preg_match('/\A([A-Z_][A-Z0-9_]*)=(.*)\z/', $line, $matches)) {
                throw ValidationException::withMessages(['variables' => 'Each variable must use KEY=value on its own line.']);
            }
            $variables[$matches[1]] = $matches[2];
        }

        return $variables;
    }
}
