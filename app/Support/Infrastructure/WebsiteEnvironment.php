<?php

declare(strict_types=1);

namespace App\Support\Infrastructure;

use App\Models\Account;
use App\Models\Environment;
use Illuminate\Validation\ValidationException;

/** The environment a website serves, which must belong to one of the account's projects (or none). */
final class WebsiteEnvironment
{
    /**
     * Check that a website is being linked to an environment in the same account and returns its ID, or null when none
     * was chosen. A foreign environment is a validation error on `environment_id`.
     *
     * @param  Account  $account
     * @param  mixed  $environmentId
     * @return string|null
     */
    public static function resolve(Account $account, mixed $environmentId): ?string
    {
        if (! is_string($environmentId) || $environmentId === '') {
            return null;
        }
        if (! Environment::query()->forAccount($account)->whereKey($environmentId)->exists()) {
            throw ValidationException::withMessages(['environment_id' => __('Choose an environment in this account.')]);
        }

        return $environmentId;
    }
}
