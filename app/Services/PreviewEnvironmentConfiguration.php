<?php

namespace App\Services;

use App\Models\Website;

class PreviewEnvironmentConfiguration
{
    /**
     * Bind preview values to the existing environment-file renderer.
     *
     * Source website environment text is deliberately not a fallback here. A
     * preview may execute a pull-request branch, so its runtime configuration
     * must be derived from preview-owned values rather than copied credentials.
     */
    public function __construct(
        private readonly EnvironmentFile $environmentFile,
    ) {}

    /**
     * Build the minimum independent runtime configuration for one preview website.
     *
     * The database password is already generated for the preview website and is
     * used by the existing local MySQL provisioning script. The application key
     * is generated here so a preview cannot decrypt or sign data with the source
     * website's key. Approved values are merged first so these preview-owned
     * credentials remain authoritative even if a future caller supplies a
     * malformed scope.
     *
     * @param  Website  $website  The persisted preview website with its generated identity and credentials.
     * @param  int  $pullRequestNumber  The verified pull-request number used in the preview marker.
     * @param  array<string, string>  $approvedSecrets  Explicitly approved, version-checked runtime values.
     * @return string The encrypted-at-rest website environment content to persist.
     */
    public function for(Website $website, int $pullRequestNumber, array $approvedSecrets = []): string
    {
        $database = $website->databaseIdentifier();

        return $this->environmentFile->merge('', [
            ...$approvedSecrets,
            'APP_ENV' => 'preview',
            'APP_DEBUG' => false,
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => 'https://'.$website->url,
            'BUILDPUSHER_PREVIEW' => $pullRequestNumber,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 3306,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $database,
            'DB_PASSWORD' => (string) $website->database_password,
        ]);
    }
}
