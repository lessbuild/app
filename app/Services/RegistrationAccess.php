<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class RegistrationAccess
{
    /**
     * Check open registration or the configured first-account bootstrap exception.
     *
     * @return bool True when registration is enabled, or first-user registration is enabled and no account exists.
     */
    public function allowsNewUser(): bool
    {
        if (config('lessbuild.registration.enabled')) {
            return true;
        }

        if (! config('lessbuild.registration.allow_first_user')) {
            return false;
        }

        return ! Schema::hasTable('users') || ! User::query()->exists();
    }

    /**
     * Serialize account resolution so two simultaneous bootstrap requests cannot
     * both observe an empty users table and create separate owners.
     */
    public function synchronized(Closure $callback): mixed
    {
        return Cache::lock('lessbuild:user-registration', 15)
            ->block(10, $callback);
    }
}
