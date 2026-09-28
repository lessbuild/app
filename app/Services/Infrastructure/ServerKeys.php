<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use phpseclib4\Crypt\PublicKeyLoader;
use Throwable;

/** SSH key helpers that need phpseclib, kept here so the rest of the app doesn't depend on it. */
class ServerKeys
{
    /**
     * The OpenSSH public key for a private key.
     */
    public function publicKeyOf(string $privateKey): string
    {
        return PublicKeyLoader::loadPrivateKey($privateKey)->getPublicKey()->toString('OpenSSH');
    }

    /**
     * Whether the text is an unencrypted private key phpseclib can read.
     */
    public function isPrivateKey(string $privateKey): bool
    {
        try {
            $this->publicKeyOf($privateKey);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
