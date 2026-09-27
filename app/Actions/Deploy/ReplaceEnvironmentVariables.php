<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReplaceEnvironmentVariables
{
    public function __construct(private readonly SaveEnvironmentVariable $save) {}

    /** Replace every variable with a pasted `.env` (KEY=value lines; # comments and blank lines skipped). Returns how many were set. */
    public function handle(User $actor, Environment $environment, string $contents): int
    {
        $variables = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $number => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/\A([A-Z_][A-Z0-9_]*)=(.*)\z/', $line, $match) !== 1) {
                throw ValidationException::withMessages(['variables' => __('Line :line isn’t KEY=value.', ['line' => $number + 1])]);
            }
            $value = $match[2];
            $variables[$match[1]] = preg_match('/\A"(.*)"\z/s', $value, $quoted) === 1 ? stripcslashes($quoted[1]) : $value;
        }

        return DB::transaction(function () use ($actor, $environment, $variables): int {
            $environment->variables()->whereNotIn('key', array_keys($variables))->delete();
            foreach ($variables as $key => $value) {
                $existing = $environment->variables()->where('key', $key)->first();
                $this->save->handle($actor, $environment, ['key' => $key, 'value' => $value, 'is_secret' => $existing->is_secret ?? true, 'scope' => $existing->scope ?? 'runtime']);
            }

            return count($variables);
        });
    }
}
