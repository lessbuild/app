<?php

namespace App\Services;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;

class SshKeyPair
{
    private PrivateKey $key;

    public function __construct()
    {
        $this->key = RSA::createKey(4096);
    }

    public function publicKey(): string
    {
        return $this->key->getPublicKey()->toString('OpenSSH');
    }

    public function privateKey(): string
    {
        return $this->key->toString('OpenSSH');
    }
}
