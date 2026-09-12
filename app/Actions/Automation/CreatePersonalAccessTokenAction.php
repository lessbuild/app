<?php

namespace App\Actions\Automation;

use App\Models\User;
use App\Services\Entitlements;
use Laravel\Sanctum\NewAccessToken;

class CreatePersonalAccessTokenAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Create an entitled personal access token with normalized abilities and an explicit expiry.
     *
     * @param  User  $actor  Account that owns the token.
     * @param  array{name: string, abilities: list<string>, expires_in_days: int|string}  $attributes  Validated token attributes.
     * @return NewAccessToken The one-time plaintext token result.
     */
    public function handle(User $actor, array $attributes): NewAccessToken
    {
        $this->entitlements->enforce($actor, 'api');

        return $actor->createToken(
            $attributes['name'],
            array_values(array_unique($attributes['abilities'])),
            now()->addDays($attributes['expires_in_days']),
        );
    }
}
