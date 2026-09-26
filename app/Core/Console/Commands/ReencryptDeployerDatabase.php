<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Migration\ReencryptDeployerDatabaseValues;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ReencryptDeployerDatabase extends Command
{
    protected $signature = 'platform:reencrypt-deployer-database
        {--apply : Re-encrypt Deployer values with the unified application key; default is a read-only preview}
        {--source-env-file= : Read APP_KEY and optional APP_CIPHER from a protected legacy environment file}';

    protected $description = 'Preview or re-encrypt Deployer encrypted model values for the unified application key';

    public function handle(ReencryptDeployerDatabaseValues $reencrypter): int
    {
        $sourceKey = null;
        $sourceCipher = null;
        $sourceEnvFile = $this->option('source-env-file');

        if (is_string($sourceEnvFile) && $sourceEnvFile !== '') {
            $source = $this->readSourceEnvironment($sourceEnvFile);
            if ($source === null) {
                return self::FAILURE;
            }

            [$sourceKey, $sourceCipher] = $source;
        }

        try {
            $report = $reencrypter->run((bool) $this->option('apply'), $sourceKey, $sourceCipher);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Measure', 'Encrypted values'],
            [
                ['Models scanned', $report['models_seen']],
                ['Encrypted fields scanned', $report['fields_seen']],
                ['Values seen', $report['values_seen']],
                ['Ready to re-encrypt', $report['ready']],
                ['Re-encrypted in this run', $report['reencrypted']],
                ['Already using unified key', $report['already_current']],
                ['Held for manual review', $report['needs_review']],
                ['Missing encrypted columns', $report['schema_gaps']],
            ],
        );

        if (! (bool) $this->option('apply')) {
            $this->line('No data was changed. Pause Deployer writers and keep a verified database backup before applying.');
        } elseif ($report['needs_review'] > 0 || $report['schema_gaps'] > 0) {
            $this->components->error('No data was changed because preview found values requiring review or missing columns.');
        }

        return $report['needs_review'] === 0 && $report['schema_gaps'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: string, 1: string}|null */
    private function readSourceEnvironment(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error('The protected legacy Deployer environment file is unavailable.');

            return null;
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            if (in_array(trim($name), ['APP_KEY', 'APP_CIPHER'], true)) {
                $values[trim($name)] = trim(trim($value), "'\"");
            }
        }

        if (($values['APP_KEY'] ?? '') === '') {
            $this->components->error('The source environment file does not contain APP_KEY.');

            return null;
        }

        return [$values['APP_KEY'], $values['APP_CIPHER'] ?? (string) config('migration.source_ciphers.deployer', config('app.cipher'))];
    }
}
