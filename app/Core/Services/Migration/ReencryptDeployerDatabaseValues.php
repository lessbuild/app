<?php

namespace App\Core\Services\Migration;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use RuntimeException;

/** Re-encrypt Deployer model casts without exposing their plaintext values. */
final class ReencryptDeployerDatabaseValues
{
    /**
     * @return array{models_seen: int, fields_seen: int, values_seen: int, ready: int, reencrypted: int, already_current: int, needs_review: int, schema_gaps: int}
     */
    public function run(bool $apply = false, ?string $sourceKey = null, ?string $sourceCipher = null): array
    {
        $sourceKey ??= config('migration.source_app_keys.deployer');
        $sourceCipher ??= (string) config('migration.source_ciphers.deployer', config('app.cipher'));

        if (! is_string($sourceKey) || $sourceKey === '') {
            throw new InvalidArgumentException('Configure MIGRATION_DEPLOYER_APP_KEY or provide a protected source environment file.');
        }

        try {
            $sourceEncrypter = new Encrypter($this->decodeKey($sourceKey), $sourceCipher);
            $targetEncrypter = new Encrypter(
                $this->decodeKey((string) config('app.key')),
                (string) config('app.cipher'),
            );
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException('Deployer source or unified application encryption configuration is invalid.', previous: $exception);
        }

        if (! $apply) {
            return $this->inspect($sourceEncrypter, $targetEncrypter, false);
        }

        return DB::connection('deployer')->transaction(function () use ($sourceEncrypter, $targetEncrypter): array {
            $preview = $this->inspect($sourceEncrypter, $targetEncrypter, false);
            if ($preview['needs_review'] > 0 || $preview['schema_gaps'] > 0) {
                return $preview;
            }

            return $this->inspect($sourceEncrypter, $targetEncrypter, true);
        });
    }

    /** @return array{models_seen: int, fields_seen: int, values_seen: int, ready: int, reencrypted: int, already_current: int, needs_review: int, schema_gaps: int} */
    private function inspect(Encrypter $source, Encrypter $target, bool $apply): array
    {
        $report = [
            'models_seen' => 0,
            'fields_seen' => 0,
            'values_seen' => 0,
            'ready' => 0,
            'reencrypted' => 0,
            'already_current' => 0,
            'needs_review' => 0,
            'schema_gaps' => 0,
        ];

        foreach ($this->encryptedModelFields() as $modelData) {
            [$table, $primaryKey, $fields] = $modelData;
            $schema = Schema::connection('deployer');
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $primaryKey)) {
                continue;
            }

            $availableFields = [];
            foreach ($fields as $field => $cast) {
                if (! $schema->hasColumn($table, $field)) {
                    $report['schema_gaps']++;

                    continue;
                }

                $availableFields[$field] = $cast;
            }

            if ($availableFields === []) {
                continue;
            }

            $report['models_seen']++;
            $report['fields_seen'] += count($availableFields);
            DB::connection('deployer')->table($table)
                ->select(array_merge([$primaryKey], array_keys($availableFields)))
                ->chunkById(250, function ($rows) use ($apply, $availableFields, $primaryKey, &$report, $source, $table, $target): void {
                    foreach ($rows as $row) {
                        foreach ($availableFields as $field => $cast) {
                            $ciphertext = $row->{$field};
                            if (! is_string($ciphertext) || $ciphertext === '') {
                                continue;
                            }

                            $report['values_seen']++;
                            if ($this->decryptableWith($ciphertext, $cast, $target)) {
                                $report['already_current']++;

                                continue;
                            }

                            try {
                                $plaintext = $source->decrypt($ciphertext, false);
                            } catch (DecryptException) {
                                $report['needs_review']++;

                                continue;
                            }

                            if (! is_string($plaintext) || ! $this->validForCast($plaintext, $cast)) {
                                $report['needs_review']++;

                                continue;
                            }

                            $report['ready']++;
                            if (! $apply) {
                                continue;
                            }

                            $reencrypted = $target->encrypt($plaintext, false);
                            $updated = DB::connection('deployer')->table($table)
                                ->where($primaryKey, $row->{$primaryKey})
                                ->where($field, $ciphertext)
                                ->update([$field => $reencrypted]);

                            if ($updated !== 1) {
                                throw new RuntimeException('A Deployer encrypted value changed during re-encryption; the transaction was rolled back.');
                            }

                            $report['reencrypted']++;
                        }
                    }
                }, $primaryKey);
        }

        return $report;
    }

    /** @return list<array{0: string, 1: string, 2: array<string, string>}> */
    private function encryptedModelFields(): array
    {
        $models = [];
        $directory = app_path('Modules/Deployer/Models');

        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file->getRelativePathname());
            $class = 'App\\Modules\\Deployer\\Models\\'.str_replace(DIRECTORY_SEPARATOR, '\\', preg_replace('/\.php$/', '', $relative));
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var Model $model */
            $model = $reflection->newInstance();
            if ($model->getConnectionName() !== 'deployer') {
                continue;
            }

            $fields = array_filter(
                $model->getCasts(),
                fn (mixed $cast): bool => is_string($cast) && str_starts_with($cast, 'encrypted'),
            );
            if ($fields === []) {
                continue;
            }

            $table = $model->getTable();
            $primaryKey = $model->getKeyName();
            $models[$table] ??= [$table, $primaryKey, []];
            $models[$table][2] = array_merge($models[$table][2], $fields);
        }

        return array_values($models);
    }

    private function decryptableWith(string $ciphertext, string $cast, Encrypter $encrypter): bool
    {
        try {
            $plaintext = $encrypter->decrypt($ciphertext, false);

            return is_string($plaintext) && $this->validForCast($plaintext, $cast);
        } catch (DecryptException) {
            return false;
        }
    }

    private function validForCast(string $plaintext, string $cast): bool
    {
        if (! str_contains($cast, ':')) {
            return true;
        }

        try {
            $decoded = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return ! str_starts_with($cast, 'encrypted:array') || is_array($decoded);
    }

    private function decodeKey(string $key): string
    {
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('The configured Deployer source key is not valid base64.');
        }

        return $decoded;
    }
}
