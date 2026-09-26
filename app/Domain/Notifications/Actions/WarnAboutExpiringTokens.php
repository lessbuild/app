<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Api\Models\ApiToken;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Notifications\ApiTokenExpiring;

final class WarnAboutExpiringTokens
{
    public const WARN_DAYS_BEFORE = 7;

    /** Tell each token's owner once, a week before it expires. Returns how many owners were told. */
    public function handle(): int
    {
        $warned = 0;
        ApiToken::query()
            ->whereNull('expiry_warned_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(self::WARN_DAYS_BEFORE)])
            ->each(function (ApiToken $token) use (&$warned): void {
                $owner = User::query()->find($token->tokenable_id);
                if ($owner !== null && $token->expires_at !== null) {
                    $owner->notify(new ApiTokenExpiring($token->account_id, $token->name, $token->expires_at));
                    $warned++;
                }
                $token->forceFill(['expiry_warned_at' => now()])->save();
            });

        return $warned;
    }
}
