<?php

namespace App\Core\Services\Migration;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/** Re-encrypt Monitor's persisted Laravel encrypted casts for the unified app key. */
final class ReencryptMonitorDatabaseValues
{
    /** @var array<string, array{0: string, 1: list<array{0: string, 1: 'string'|'array'}>}> */
    private const FIELDS = [
        'alert_destinations' => ['id', [['endpoint_url', 'string'], ['signing_secret', 'string']]],
        'monitors' => ['id', [
            ['request_url', 'string'], ['bearer_token', 'string'], ['body_contains', 'string'],
            ['hostname', 'string'], ['dns_expected', 'array'],
        ]],
        'monitor_checks' => ['id', [['evidence', 'array']]],
        'alert_deliveries' => ['id', [['payload', 'array']]],
        'ingest_payloads' => ['ingest_receipt_id', [['payload', 'array']]],
    ];

    /**
     * @return array{values_seen: int, ready: int, reencrypted: int, already_current: int, needs_review: int}
     */
    public function run(bool $apply = false): array
    {
        $sourceKey = config('migration.source_app_keys.monitor');
        if (! is_string($sourceKey) || $sourceKey === '') {
            throw new InvalidArgumentException('Configure MIGRATION_MONITOR_APP_KEY before inspecting Monitor encrypted data.');
        }

        $sourceCipher = (string) config('migration.source_ciphers.monitor', config('app.cipher'));
        try {
            $sourceEncrypter = new Encrypter($this->decodeKey($sourceKey), $sourceCipher);
            $targetEncrypter = new Encrypter(
                $this->decodeKey((string) config('app.key')),
                (string) config('app.cipher'),
            );
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException('Monitor source or unified application encryption configuration is invalid.', previous: $exception);
        }

        $report = [
            'values_seen' => 0,
            'ready' => 0,
            'reencrypted' => 0,
            'already_current' => 0,
            'needs_review' => 0,
        ];

        foreach (self::FIELDS as $table => [$primaryKey, $fields]) {
            $schema = Schema::connection('monitor');
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $primaryKey)) {
                continue;
            }

            $fields = array_values(array_filter($fields, fn (array $field): bool => $schema->hasColumn($table, $field[0])));
            if ($fields === []) {
                continue;
            }

            DB::connection('monitor')->table($table)
                ->select(array_merge([$primaryKey], array_column($fields, 0)))
                ->chunkById(250, function ($rows) use ($apply, $fields, $primaryKey, &$report, $sourceEncrypter, $table, $targetEncrypter): void {
                    foreach ($rows as $row) {
                        $updates = [];

                        foreach ($fields as [$field, $type]) {
                            $value = $row->{$field};
                            if (! is_string($value) || $value === '') {
                                continue;
                            }

                            $report['values_seen']++;
                            if ($this->isCurrentCiphertext($value, $type, $targetEncrypter)) {
                                $report['already_current']++;

                                continue;
                            }

                            $reencrypted = $this->reencrypt($value, $type, $sourceEncrypter, $targetEncrypter);
                            if ($reencrypted === null) {
                                $report['needs_review']++;

                                continue;
                            }

                            $report['ready']++;
                            if ($apply) {
                                $updates[$field] = $reencrypted;
                            }
                        }

                        if ($updates !== []) {
                            DB::connection('monitor')->table($table)
                                ->where($primaryKey, $row->{$primaryKey})
                                ->update($updates);
                            $report['reencrypted'] += count($updates);
                        }
                    }
                }, $primaryKey);
        }

        return $report;
    }

    private function isCurrentCiphertext(string $value, string $type, Encrypter $encrypter): bool
    {
        try {
            $decrypted = $encrypter->decrypt($value, false);

            return $type === 'array'
                ? is_string($decrypted) && is_array(json_decode($decrypted, true))
                : is_string($decrypted);
        } catch (DecryptException) {
            return false;
        }
    }

    private function reencrypt(string $value, string $type, Encrypter $source, Encrypter $target): ?string
    {
        try {
            if (Encrypter::appearsEncrypted($value)) {
                $plaintext = $source->decrypt($value, false);
                if ($type === 'array' && is_string($plaintext)) {
                    $plaintext = json_decode($plaintext, true);
                }
            } elseif ($type === 'array') {
                $plaintext = json_decode($value, true);
                if (! is_array($plaintext)) {
                    return null;
                }
            } else {
                $plaintext = $value;
            }

            if ($type === 'array' && ! is_array($plaintext)) {
                return null;
            }
            if ($type === 'string' && ! is_string($plaintext)) {
                return null;
            }

            return $target->encrypt(
                $type === 'array' ? json_encode($plaintext, JSON_THROW_ON_ERROR) : $plaintext,
                false,
            );
        } catch (DecryptException|RuntimeException|\JsonException) {
            return null;
        }
    }

    private function decodeKey(string $key): string
    {
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('MIGRATION_MONITOR_APP_KEY is not a valid base64 key.');
        }

        return $decoded;
    }
}
