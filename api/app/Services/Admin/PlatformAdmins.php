<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Account;
use App\Models\PlatformAdminEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Grants and revokes platform administration, and keeps the trail of what admins do. */
final class PlatformAdmins
{
    /**
     * Make someone a platform admin. Returns whether anything changed.
     *
     * @param  User  $user
     * @param  User|null  $actor  null from the command line
     * @param  string  $source  cli or admin
     * @return bool
     */
    public function grant(User $user, ?User $actor, string $source): bool
    {
        return $this->change($user, $actor, $source, true);
    }

    /**
     * Take platform administration away. Refuses to remove the last admin unless allowed. Returns whether anything
     * changed.
     *
     * @param  User  $user
     * @param  User|null  $actor
     * @param  string  $source
     * @param  bool  $allowLast
     * @return bool
     */
    public function revoke(User $user, ?User $actor, string $source, bool $allowLast = false): bool
    {
        return $this->change($user, $actor, $source, false, $allowLast);
    }

    /**
     * Record something an admin did or looked at, in the admin trail.
     *
     * @param  User|null  $actor
     * @param  string  $action  e.g. customer.viewed, job.retried
     * @param  string  $description
     * @param  User|null  $subjectUser
     * @param  Account|null  $subjectAccount
     * @param  string  $source
     * @return PlatformAdminEvent
     */
    public function record(?User $actor, string $action, string $description, ?User $subjectUser = null, ?Account $subjectAccount = null, string $source = 'admin'): PlatformAdminEvent
    {
        $event = new PlatformAdminEvent;
        $event->forceFill([
            'actor_id' => $actor?->id, 'action' => $action, 'source' => $source, 'subject_user_id' => $subjectUser?->id,
            'subject_account_id' => $subjectAccount?->id, 'description' => mb_substr($description, 0, 500),
        ])->save();

        return $event;
    }

    /**
     * Set the flag under a lock, keeping one admin at least unless allowed, and record the change.
     *
     * @param  User  $user
     * @param  User|null  $actor
     * @param  string  $source
     * @param  bool  $grant
     * @param  bool  $allowLast
     * @return bool
     */
    private function change(User $user, ?User $actor, string $source, bool $grant, bool $allowLast = false): bool
    {
        return DB::transaction(function () use ($user, $actor, $source, $grant, $allowLast): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->is_platform_admin === $grant) {
                return false;
            }
            if (! $grant && ! $allowLast && ! User::query()->where('is_platform_admin', true)->whereKeyNot($locked->id)->lockForUpdate()->exists()) {
                throw new RuntimeException('Refusing to revoke the last platform administrator.');
            }
            $locked->forceFill(['is_platform_admin' => $grant, 'platform_admin_granted_at' => $grant ? now() : null])->save();
            $this->record($actor, $grant ? 'admin.granted' : 'admin.revoked', ($grant ? 'Granted' : 'Revoked')." platform administration for {$locked->email}.", $locked, null, $source);

            return true;
        }, attempts: 3);
    }
}
