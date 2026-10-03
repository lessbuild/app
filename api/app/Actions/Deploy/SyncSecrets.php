<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\EnvironmentVariable;
use App\Models\SecretSync;
use App\Services\Deploy\SecretSources;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SyncSecrets
{
    /**
     * Create a new SyncSecrets instance.
     *
     * @param  SecretSources  $sources  Reads the password manager.
     */
    public function __construct(private readonly SecretSources $sources) {}

    /**
     * Copy a password manager's secrets into the environment as secret variables (runtime and build) that the sync
     * manages: new keys are added, changed values get a new version, and keys gone from the source are removed.
     * Variables someone set by hand, and keys that aren't valid variable names, are left alone and reported. The next
     * deploy uses the new values.
     *
     * @param  SecretSync  $sync
     * @return array{added: int, updated: int, removed: int, skipped: list<string>}|null null when the source couldn't be read
     */
    public function handle(SecretSync $sync): ?array
    {
        try {
            $values = $this->sources->fetch($sync);
        } catch (Throwable $exception) {
            $sync->forceFill(['last_error' => Str::limit($exception->getMessage(), 480), 'last_synced_at' => now()])->save();

            return null;
        }
        $result = DB::transaction(function () use ($sync, $values): array {
            $result = ['added' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => []];
            $existing = EnvironmentVariable::query()->where('environment_id', $sync->environment_id)->lockForUpdate()->get()->keyBy('key');
            foreach ($values as $key => $value) {
                $variable = $existing->get($key);
                if (preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $key) !== 1 || ($variable !== null && $variable->secret_sync_id !== $sync->id)) {
                    $result['skipped'][] = $key;

                    continue;
                }
                if ($variable !== null && $variable->value === $value) {
                    continue;
                }
                $version = ($variable->current_version ?? 0) + 1;
                $variable ??= new EnvironmentVariable;
                $variable->forceFill(['environment_id' => $sync->environment_id, 'key' => $key, 'value' => $value, 'is_secret' => true, 'scope' => 'all', 'current_version' => $version, 'rotated_at' => $version > 1 ? now() : null, 'secret_sync_id' => $sync->id, 'updated_by' => null])->save();
                $variable->versions()->forceCreate(['created_by' => null, 'version' => $version, 'value' => $value]);
                $result[$version === 1 ? 'added' : 'updated']++;
            }
            foreach ($existing as $key => $variable) {
                if ($variable->secret_sync_id === $sync->id && ! array_key_exists((string) $key, $values)) {
                    $variable->delete();
                    $result['removed']++;
                }
            }

            return $result;
        });
        $sync->forceFill(['last_synced_at' => now(), 'last_error' => null, 'last_result' => $result])->save();

        return $result;
    }
}
