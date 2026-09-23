<?php

namespace App\Modules\Deployer\Actions\Provider;

use App\Modules\Deployer\Services\GitHubAppPrivateKeyStore;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StoreGitHubAppPrivateKeyAction
{
    public function __construct(private readonly GitHubAppPrivateKeyStore $privateKeys) {}

    /**
     * Validate and atomically store a GitHub App RSA private key without returning or logging its contents.
     *
     * @param  string  $contents  The uploaded PEM contents.
     * @return void No value; the key is stored in the isolated private application directory.
     *
     * @throws ValidationException When the uploaded file is not an unencrypted RSA private key.
     */
    public function handle(string $contents): void
    {
        try {
            $this->privateKeys->store($contents);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'private_key' => __('Upload the unencrypted RSA private key downloaded from your GitHub App settings.'),
            ]);
        }
    }
}
