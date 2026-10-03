<?php

declare(strict_types=1);

namespace App\Contracts\Analytics;

use Carbon\CarbonImmutable;

/** An ad platform whose daily campaign spend can be read with the account holder's permission. */
interface AdPlatform
{
    /**
     * Determine whether the platform has the credentials to connect.
     *
     * @return bool
     */
    public function configured(): bool;

    /**
     * Get the sign-in address that asks for read access to ads reporting.
     *
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $state): string;

    /**
     * Swap the code the platform sent back for a long-lived credential.
     *
     * @param  string  $code
     * @return string
     */
    public function exchange(string $code): string;

    /**
     * List the ad accounts the credential can read.
     *
     * @param  string  $credential
     * @return list<array{id: string, name: string, currency: string|null}>
     */
    public function accounts(string $credential): array;

    /**
     * Read an ad account's cost, clicks and impressions per campaign and day.
     *
     * @param  string  $credential
     * @param  string  $accountId
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return list<array{date: string, campaign: string, cost: float, currency: string, clicks: int|null, impressions: int|null}>
     */
    public function spend(string $credential, string $accountId, CarbonImmutable $from, CarbonImmutable $until): array;
}
