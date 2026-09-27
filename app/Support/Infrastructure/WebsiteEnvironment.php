<?php

declare(strict_types=1);

namespace App\Support\Infrastructure;

use App\Models\Account;
use App\Models\Environment;
use Illuminate\Validation\ValidationException;

/** The environment a website serves, which must belong to one of the account's projects (or none). */
final class WebsiteEnvironment
{
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
