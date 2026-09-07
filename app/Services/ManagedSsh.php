<?php

namespace App\Services;

use RuntimeException;
use Spatie\Ssh\Ssh;

class ManagedSsh extends Ssh
{
    private ?string $temporaryPrivateKey = null;

    private ?string $temporaryKnownHosts = null;

    /**
     * Write private-key bytes to a restricted temporary file and configure the SSH client.
     *
     * @param  string  $privateKey  The private-key contents to write with mode 0600.
     * @return self This SSH client for chaining; previously managed temporary files are removed first.
     *
     * @throws RuntimeException If the temporary directory or key file cannot be created securely.
     */
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

    /**
     * Attempt to remove managed temporary key and known-hosts files.
     *
     * @return void No value; clears the tracked paths after attempting file deletion.
     */
    public function close(): void
    {
        if ($this->temporaryPrivateKey !== null) {
            @unlink($this->temporaryPrivateKey);
            $this->temporaryPrivateKey = null;
        }
        if ($this->temporaryKnownHosts !== null) {
            @unlink($this->temporaryKnownHosts);
            $this->temporaryKnownHosts = null;
        }
    }

    /**
     * Write a restricted known-hosts file and enable strict SSH host-key checking.
     *
     * @param  string  $knownHost  The known-hosts entry to trim and write with a trailing newline.
     * @return self This SSH client with explicit host-key validation options.
     *
     * @throws RuntimeException If the temporary directory or known-hosts file cannot be created securely.
     */
    public function useKnownHost(string $knownHost): self
    {
        $directory = storage_path('app/ssh');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the temporary SSH directory.');
        }
        $path = tempnam($directory, 'hosts-');
        if ($path === false || file_put_contents($path, trim($knownHost)."\n", LOCK_EX) === false || ! chmod($path, 0600)) {
            if (is_string($path)) {
                @unlink($path);
            }
            throw new RuntimeException('Unable to secure the SSH known-hosts file.');
        }
        $this->temporaryKnownHosts = $path;
        $this->addExtraOption('-o StrictHostKeyChecking=yes -o UserKnownHostsFile='.escapeshellarg($path));

        return $this;
    }

    /**
     * Remove tracked temporary SSH credentials and host records when the client is destroyed.
     */
    public function __destruct()
    {
        $this->close();
    }
}
