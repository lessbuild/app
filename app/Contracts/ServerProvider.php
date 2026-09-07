<?php

namespace App\Contracts;

use App\Data\CloudServerData;
use App\Data\CloudSshKeyData;

interface ServerProvider
{
    /**
     * Identify the cloud platform in messages and provider selections.
     *
     * @return string Human-readable provider name.
     */
    public function name(): string;

    /**
     * Register the public key or reuse an exact existing match in the provider account.
     *
     * @param  string  $name  Label for a newly created provider key.
     * @param  string  $publicKey  Public SSH key material to register.
     * @return CloudSshKeyData Provider key reference and whether this call created it.
     */
    public function createSshKey(string $name, string $publicKey): CloudSshKeyData;

    /**
     * Remove a provider SSH key, accepting an already absent key as success.
     *
     * @param  string  $fingerprint  Provider fingerprint or opaque key ID returned during registration.
     * @return bool Whether the provider accepted deletion or reported the key absent.
     */
    public function deleteSshKey(string $fingerprint): bool;

    /**
     * Provision a cloud instance from the shared server parameters.
     *
     * @param  array{name: string, region: string, size: string, image: int|string, ssh_keys?: list<int|string>, user_data?: string|null, ...}  $parameters  Provider identifiers and optional bootstrap settings; additional provider fields may be supplied.
     * @return CloudServerData Normalized instance metadata; addresses may still be unavailable.
     */
    public function createServer(array $parameters): CloudServerData;

    /**
     * Fetch current instance metadata from the cloud account.
     *
     * @param  int|string  $identifier  Native instance ID returned by the provider.
     * @return CloudServerData Normalized instance metadata and any assigned IP addresses.
     */
    public function server(int|string $identifier): CloudServerData;

    /**
     * Delete an instance, accepting an already absent instance as success.
     *
     * @param  int|string  $identifier  Native instance ID returned by the provider.
     * @return bool Whether the provider accepted deletion or reported the instance absent.
     */
    public function deleteServer(int|string $identifier): bool;

    /**
     * Fetch the provider region/location catalog.
     *
     * @return list<array<string, mixed>> Provider-specific catalog records; field names are not normalized.
     */
    public function regions(): array;

    /**
     * Fetch the provider instance size/plan catalog.
     *
     * @return list<array<string, mixed>> Provider-specific catalog records; field names are not normalized.
     */
    public function sizes(): array;

    /**
     * Fetch the provider operating-system image catalog.
     *
     * @return list<array<string, mixed>> Provider-specific catalog records; field names are not normalized.
     */
    public function images(): array;
}
