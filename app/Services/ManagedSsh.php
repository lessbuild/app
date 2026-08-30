<?php

namespace App\Services;

use RuntimeException;
use Spatie\Ssh\Ssh;

class ManagedSsh extends Ssh
{
    private ?string $temporaryPrivateKey = null;

    public function usePrivateKeyContents(string $privateKey): self
    {
        $this->close();
        $directory = storage_path('app/ssh');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the temporary SSH key directory.');
        }

        if (! chmod($directory, 0700)) {
            throw new RuntimeException('Unable to secure the temporary SSH key directory.');
        }

        $path = tempnam($directory, 'key-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary SSH private key.');
        }

        if (file_put_contents($path, $privateKey, LOCK_EX) === false || ! chmod($path, 0600)) {
            @unlink($path);

            throw new RuntimeException('Unable to secure the temporary SSH private key.');
        }

        $this->temporaryPrivateKey = $path;

        parent::usePrivateKey($path);

        return $this;
    }

    public function close(): void
    {
        if ($this->temporaryPrivateKey !== null) {
            @unlink($this->temporaryPrivateKey);
            $this->temporaryPrivateKey = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
