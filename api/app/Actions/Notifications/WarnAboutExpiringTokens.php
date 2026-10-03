<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\ApiToken;
use App\Models\User;
use App\Notifications\ApiTokenExpiring;

final class WarnAboutExpiringTokens
{
    public const WARN_DAYS_BEFORE = 7;

    /**
     * Tell each token's owner once, a week before it expires. Returns how many owners were told.
     *
     * @return int
     */
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
