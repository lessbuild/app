<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\EnvironmentVariable;
use App\Models\Preview;
use App\Models\PreviewSecretApproval;
use App\Models\Website;

/**
 * Writes a preview website's `.env`. A preview runs a pull request's code, so it never inherits the source website's
 * `.env` or secrets: it gets the source environment's non-secret runtime variables, the secrets someone approved for
 * this exact revision, and its own key, URL and database, which nothing can override.
 */
final class PreviewConfiguration
{
    /**
     * Create a new PreviewConfiguration instance.
     *
     * Writes preview `.env` files.
     *
     * @param  EnvironmentFile  $file  Sets variables in `.env` text.
     */
    public function __construct(private readonly EnvironmentFile $file) {}

    /**
     * Build the `.env` for the preview's website: source variables, then approved secrets, then the preview's own
     * values. An `APP_KEY` already in the website's `.env` is kept, so sessions and encrypted data survive new
     * revisions.
     *
     * @param  Preview  $preview
     * @param  Website  $website
     * @return string
     */
    public function environmentFile(Preview $preview, Website $website): string
    {
        $key = preg_match('/^APP_KEY="?(base64:[A-Za-z0-9+\/=]{40,})"?$/m', (string) $website->env_file, $match) === 1
            ? $match[1]
            : 'base64:'.base64_encode(random_bytes(32));
        $database = $website->databaseIdentifier();

        return $this->file->merge('', [
            ...$this->sourceVariables($preview),
            ...$this->approvedSecrets($preview),
            'APP_ENV' => 'preview',
            'APP_DEBUG' => false,
            'APP_KEY' => $key,
            'APP_URL' => 'https://'.$website->url,
            'BUILDPUSHER_PREVIEW' => $preview->pull_request_number ?? $preview->source_branch,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 3306,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $database,
            'DB_PASSWORD' => (string) $website->database_password,
        ]);
    }

    /**
     * Get the secrets approved for the preview's current revision, as key => value. The whole approval lapses (an
     * empty list) when it was for another source environment, a variable is gone or no longer a runtime secret, or a
     * secret has changed version since it was approved.
     *
     * @param  Preview  $preview
     * @return array<string, string>
     */
    public function approvedSecrets(Preview $preview): array
    {
        $approval = $preview->secretApprovals()->where('revision', $preview->revision)->whereNull('revoked_at')->latest('approved_at')->first();
        if ($approval === null || $approval->source_environment_id !== $preview->source_environment_id || $approval->variable_versions === []) {
            return [];
        }
        $variables = EnvironmentVariable::query()->where('environment_id', $approval->source_environment_id)
            ->whereKey(array_map('intval', array_keys($approval->variable_versions)))->get()->keyBy('id');
        $values = [];
        foreach ($approval->variable_versions as $id => $version) {
            $variable = $variables->get((int) $id);
            if ($variable === null || ! self::isApprovable($variable) || $variable->current_version !== (int) $version) {
                return [];
            }
            $values[$variable->key] = $variable->value;
        }

        return $values;
    }

    /**
     * Determine whether a variable may be approved for previews: a runtime secret whose key isn't one the preview
     * sets itself.
     *
     * @param  EnvironmentVariable  $variable
     * @return bool
     */
    public static function isApprovable(EnvironmentVariable $variable): bool
    {
        return $variable->is_secret && in_array($variable->scope, ['runtime', 'all'], true) && ! in_array($variable->key, PreviewSecretApproval::PROTECTED_KEYS, true);
    }

    /**
     * Get the source environment's non-secret runtime variables, as key => value.
     *
     * @param  Preview  $preview
     * @return array<string, string>
     */
    private function sourceVariables(Preview $preview): array
    {
        if ($preview->source_environment_id === null) {
            return [];
        }

        return EnvironmentVariable::query()->where('environment_id', $preview->source_environment_id)->where('is_secret', false)
            ->whereIn('scope', ['runtime', 'all'])->pluck('value', 'key')->all();
    }
}
