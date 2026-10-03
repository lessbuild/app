<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Account;
use App\Models\FeatureFlag;

/** Answers whether a feature flag is on, reading each flag once per request. */
final class FeatureFlags
{
    /**
     * Flags read so far in this request, by key; null for a key with no flag.
     *
     * @var array<string, FeatureFlag|null>
     */
    private array $read = [];

    /**
     * Determine whether a flag is on for an account (or for everyone when no account is given). Unknown keys are off.
     *
     * @param  string  $key
     * @param  Account|null  $account
     * @return bool
     */
    public function enabled(string $key, ?Account $account = null): bool
    {
        if (! array_key_exists($key, $this->read)) {
            $this->read[$key] = FeatureFlag::query()->where('key', $key)->first();
        }

        return $this->read[$key]?->isOnFor($account) ?? false;
    }

    /**
     * Forget what was read, after a flag changes.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->read = [];
    }
}
