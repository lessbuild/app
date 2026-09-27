<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use App\Data\ApiTokens\CreateApiTokenData;
use App\Data\ApiTokens\NewApiToken;
use App\Events\ApiTokens\ApiTokenCreated;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CreateApiToken
{
    /**
     * Creates an API token acting as the actor in the account. Write scopes bring their read scope with them, and only
     * the token's hash is stored; the plain token is returned once.
     */
    public function handle(User $actor, Account $account, CreateApiTokenData $data): NewApiToken
    {
        Gate::forUser($actor)->authorize('manageApiTokens', $account);

        $scopes = [];
        foreach ($data->scopes as $scope) {
            $scopes[] = $scope->value;
            if ($scope->implied() !== null) {
                $scopes[] = $scope->implied()->value;
            }
        }
        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        $plainText = $actor->generateTokenString();
        $token = new ApiToken;
        $token->tokenable()->associate($actor);
        $token->account()->associate($account);
        $token->forceFill([
            'name' => $data->name,
            'token' => hash('sha256', $plainText),
            'abilities' => $scopes,
            'expires_at' => $data->expiresInDays !== null ? now()->addDays($data->expiresInDays) : null,
        ])->save();

        ApiTokenCreated::dispatch($token, $actor);

        return new NewApiToken($token, $token->id.'|'.$plainText);
    }
}
