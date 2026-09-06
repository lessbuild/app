<?php

namespace App\Services;

use phpseclib4\Crypt\RSA;
use phpseclib4\Crypt\RSA\PrivateKey;

class SshKeyPair
{
    private readonly PrivateKey $key;

    /** Create a new 4096-bit RSA key pair for managed SSH access. */
    public function __construct()
    {
        $this->key = RSA::createKey(4096);
    }

    /** Return the public key in OpenSSH authorized_keys format. */
    public function publicKey(): string
    {
        return $this->key->getPublicKey()->toString('OpenSSH');
    }

    /** Return the unencrypted OpenSSH private key for encrypted application storage. */
    public function privateKey(): string
    {
        return $this->key->toString('OpenSSH');
    }
}
