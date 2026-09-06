<?php

namespace Tests\Unit;

use App\Services\SshKeyPair;
use phpseclib4\Crypt\PublicKeyLoader;
use PHPUnit\Framework\TestCase;

class SshKeyPairTest extends TestCase
{
    public function test_it_generates_a_matching_openssh_key_pair(): void
    {
        $keyPair = new SshKeyPair;

        $privateKey = PublicKeyLoader::loadPrivateKey($keyPair->privateKey());

        $this->assertStringStartsWith('ssh-rsa ', $keyPair->publicKey());
        $this->assertSame(
            $privateKey->getPublicKey()->toString('OpenSSH'),
            $keyPair->publicKey(),
        );
    }
}
