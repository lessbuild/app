<?php

declare(strict_types=1);

namespace App\Contracts\Security;

/** The network look-ups the domain check needs, behind a contract so tests answer them without the internet. */
interface DomainProbe
{
    /**
     * Connect to a host over TLS and describe its certificate: whether it's trusted for the name, when it expires and
     * who issued it, or why the connection failed.
     *
     * @param  string  $host
     * @return array{valid: bool, expires_at: int|null, issuer: string|null, error: string|null}
     */
    public function certificate(string $host): array;

    /**
     * Determine whether a host still accepts TLS 1.0 or 1.1.
     *
     * @param  string  $host
     * @return bool
     */
    public function acceptsOldTls(string $host): bool;

    /**
     * Request a URL without following redirects and return the status and headers (lower-case names), or null when it
     * couldn't be reached.
     *
     * @param  string  $url
     * @return array{status: int, headers: array<string, string>}|null
     */
    public function fetch(string $url): ?array;

    /**
     * Get a name's TXT records.
     *
     * @param  string  $name
     * @return list<string>
     */
    public function txt(string $name): array;

    /**
     * Get the target of a name's CNAME record, or null when it has none.
     *
     * @param  string  $name
     * @return string|null
     */
    public function cname(string $name): ?string;

    /**
     * Determine whether a name resolves to any address.
     *
     * @param  string  $name
     * @return bool
     */
    public function resolves(string $name): bool;
}
