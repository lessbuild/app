<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class SshHostIdentity
{
    /** @return array{known_host: string, fingerprint: string, algorithm: string} */
    public function scan(string $host, int $port): array
    {
        if (! filter_var($host, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535) {
            throw new RuntimeException('The SSH host or port is invalid.');
        }

        $scan = new Process(['ssh-keyscan', '-T', (string) max(1, (int) config('lessbuild.ssh_connect_timeout', 10)), '-p', (string) $port, '-t', 'ed25519,ecdsa,rsa', $host]);
        $scan->setTimeout(max(2, (int) config('lessbuild.ssh_connect_timeout', 10) + 2));
        $scan->run();
        $lines = collect(preg_split('/\R/', trim($scan->getOutput())) ?: [])->filter(fn (string $line) => $line !== '' && ! str_starts_with($line, '#'));
        if (! $scan->isSuccessful() || $lines->isEmpty()) {
            throw new RuntimeException('Unable to read the SSH host identity. Confirm that SSH is reachable at this address and port.');
        }

        // Prefer modern Ed25519, then ECDSA, then RSA for older hosts.
        $line = $lines->first(fn (string $line) => str_contains($line, ' ssh-ed25519 '))
            ?? $lines->first(fn (string $line) => str_contains($line, ' ecdsa-'))
            ?? $lines->first();
        $parts = preg_split('/\s+/', $line);
        if (count($parts) !== 3 || ! preg_match('/\A(?:ssh-ed25519|ecdsa-sha2-nistp(?:256|384|521)|rsa-sha2-(?:256|512)|ssh-rsa)\z/', $parts[1])) {
            throw new RuntimeException('The SSH host returned an unsupported public key.');
        }

        $fingerprintProcess = new Process(['ssh-keygen', '-lf', '-', '-E', 'sha256']);
        $fingerprintProcess->setInput($line."\n");
        $fingerprintProcess->setTimeout(5);
        $fingerprintProcess->run();
        if (! $fingerprintProcess->isSuccessful() || ! preg_match('/\b(SHA256:[A-Za-z0-9+\/=]+)\b/', $fingerprintProcess->getOutput(), $match)) {
            throw new RuntimeException('Unable to fingerprint the SSH host public key.');
        }

        return ['known_host' => $line, 'fingerprint' => $match[1], 'algorithm' => $parts[1]];
    }
}
