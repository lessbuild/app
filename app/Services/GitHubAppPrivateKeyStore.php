<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GitHubAppPrivateKeyStore
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * Return the first valid RSA private key from the configured value or the local setup file.
     *
     * @return string|null The normalized unencrypted PEM key, or null when no usable key exists.
     */
    public function read(): ?string
    {
        foreach ($this->sources() as $source) {
            $contents = $this->sourceContents($source);

            if ($contents !== null && $this->isValid($contents)) {
                return $this->normalize($contents);
            }
        }

        return null;
    }

    /**
     * Atomically store a validated, unencrypted RSA private key outside the public directory.
     *
     * @param  string  $contents  The uploaded PEM contents.
     * @return void No value; replaces the isolated setup key only after validation and a complete write.
     *
     * @throws InvalidArgumentException When the contents are not a usable GitHub App key.
     * @throws RuntimeException When the private key cannot be stored safely.
     */
    public function store(string $contents): void
    {
        $contents = $this->normalize($contents);
        if (! $this->isValid($contents)) {
            throw new InvalidArgumentException;
        }

        $path = $this->path();
        $directory = dirname($path);
        $this->files->ensureDirectoryExists($directory, 0700);
        $temporary = $path.'.'.Str::uuid().'.tmp';
        $complete = false;

        try {
            if ($this->files->put($temporary, $contents) === false || ! chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to store the GitHub App private key.');
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to store the GitHub App private key.');
            }

            $complete = true;
        } finally {
            if (! $complete && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Return the private path used by the dev-only setup flow. */
    public function path(): string
    {
        return (string) config('github-app.private_key_path');
    }

    /**
     * @return list<string> Configured key content/path followed by the isolated setup path.
     */
    private function sources(): array
    {
        return array_values(array_filter([
            (string) config('github-app.private_key'),
            $this->path(),
        ], static fn (string $source): bool => $source !== ''));
    }

    private function sourceContents(string $source): ?string
    {
        $normalized = $this->normalize($source);
        if (str_contains($normalized, 'BEGIN')) {
            return $normalized;
        }

        if (! is_file($source) || ! is_readable($source)) {
            return null;
        }

        $contents = file_get_contents($source);

        return is_string($contents) ? $contents : null;
    }

    private function normalize(string $contents): string
    {
        return trim(str_replace(["\r\n", "\r", '\\n'], ["\n", "\n", "\n"], $contents));
    }

    private function isValid(string $contents): bool
    {
        $key = openssl_pkey_get_private($contents);
        if ($key === false) {
            return false;
        }

        $details = openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA;
    }
}
