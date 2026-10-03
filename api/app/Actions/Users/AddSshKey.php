<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\AccountRuleViolation;
use App\Jobs\Security\SyncSshAccess;
use App\Models\User;
use App\Models\UserSshKey;

final class AddSshKey
{
    /**
     * Add an SSH public key to someone's profile; it's installed on every server they already have access to.
     *
     * @param  User  $user
     * @param  string  $name
     * @param  string  $publicKey
     * @return UserSshKey
     */
    public function handle(User $user, string $name, string $publicKey): UserSshKey
    {
        $parsed = UserSshKey::parse($publicKey) ?? throw new AccountRuleViolation('public_key', __('Paste a public key, such as the contents of ~/.ssh/id_ed25519.pub.'));
        if (UserSshKey::query()->where('user_id', $user->id)->where('fingerprint', $parsed['fingerprint'])->exists()) {
            throw new AccountRuleViolation('public_key', __('You’ve already added this key.'));
        }
        $key = new UserSshKey;
        $key->forceFill(['user_id' => $user->id, 'name' => mb_substr(trim($name), 0, 100), 'public_key' => $parsed['key'], 'fingerprint' => $parsed['fingerprint']])->save();
        SyncSshAccess::everywhere($user);

        return $key;
    }
}
