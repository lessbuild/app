<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\AccountRuleViolation;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Monitoring\WebPush;

final class AddPushDevice
{
    /**
     * How many devices one person can have.
     *
     * @var int
     */
    public const MAX_DEVICES = 10;

    /**
     * Remember a device's push subscription (from the browser's PushManager) so alerts can reach it. Subscribing the
     * same device again updates it.
     *
     * @param  User  $user
     * @param  string  $endpoint
     * @param  string  $publicKey  base64url P-256 key
     * @param  string  $authSecret  base64url
     * @param  string|null  $device  a short description, such as iPhone · Safari
     * @return PushSubscription
     */
    public function handle(User $user, string $endpoint, string $publicKey, string $authSecret, ?string $device): PushSubscription
    {
        if (! WebPush::allowed($endpoint) || strlen(WebPush::decode($publicKey)) !== 65 || strlen(WebPush::decode($authSecret)) !== 16) {
            throw new AccountRuleViolation('endpoint', __('This browser’s push service isn’t supported.'));
        }
        $hash = hash('sha256', $endpoint);
        $subscription = PushSubscription::query()->where('endpoint_hash', $hash)->first() ?? new PushSubscription;
        if (! $subscription->exists && PushSubscription::query()->where('user_id', $user->id)->count() >= self::MAX_DEVICES) {
            throw new AccountRuleViolation('endpoint', __('Remove a device first: up to :count can get notifications.', ['count' => self::MAX_DEVICES]));
        }
        $subscription->forceFill(['user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => $hash, 'public_key' => $publicKey, 'auth_secret' => $authSecret, 'device' => $device !== null ? mb_substr($device, 0, 120) : null])->save();

        return $subscription;
    }
}
