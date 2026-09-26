<?php

namespace App\Core\Services\Admin;

use App\Core\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Grants and revokes platform administration with an append-only history. */
final class PlatformAdminAccess
{
    /** @return bool Whether the flag changed. */
    public function grant(PlatformUser $user, ?PlatformUser $actor, string $source): bool
    {
        return $this->change($user, $actor, $source, true);
    }

    /**
     * @return bool Whether the flag changed.
     *
     * @throws RuntimeException When revoking the last remaining administrator without explicit consent.
     */
    public function revoke(PlatformUser $user, ?PlatformUser $actor, string $source, bool $allowLastAdmin = false): bool
    {
        return $this->change($user, $actor, $source, false, $allowLastAdmin);
    }

    private function change(PlatformUser $user, ?PlatformUser $actor, string $source, bool $grant, bool $allowLastAdmin = false): bool
    {
        return DB::connection('core')->transaction(function () use ($user, $actor, $source, $grant, $allowLastAdmin): bool {
            $locked = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            if ((bool) $locked->is_platform_admin === $grant) {
                return false;
            }
            if (! $grant && ! $allowLastAdmin) {
                $others = PlatformUser::query()->where('is_platform_admin', true)
                    ->where('status', 'active')->whereKeyNot($locked->getKey())->lockForUpdate()->count();
                if ($others === 0) {
                    throw new RuntimeException('Refusing to revoke the last platform administrator.');
                }
            }

            $locked->forceFill([
                'is_platform_admin' => $grant,
                'platform_admin_granted_at' => $grant ? now() : null,
            ])->save();
            DB::connection('core')->table('platform_admin_events')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => (string) $locked->getKey(),
                'actor_user_id' => $actor === null ? null : (string) $actor->getKey(),
                'action' => $grant ? 'granted' : 'revoked',
                'source' => $source,
                'created_at' => now(),
            ]);

            return true;
        }, attempts: 3);
    }
}
