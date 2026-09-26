<?php

namespace App\Core\Services\Workspaces;

use App\Core\Data\Workspaces\CreatedWorkspaceInvitation;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceInvitation;
use App\Core\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateWorkspaceInvitation
{
    private const INVITABLE_ROLES = ['admin', 'billing', 'member', 'viewer'];

    public function handle(PlatformUser $inviter, Workspace $workspace, string $email, string $role): CreatedWorkspaceInvitation
    {
        if (! in_array($role, self::INVITABLE_ROLES, true)) {
            throw ValidationException::withMessages(['role' => __('Choose a valid workspace role.')]);
        }

        $normalizedEmail = Str::lower(trim($email));
        $token = bin2hex(random_bytes(32));

        $invitation = DB::connection('core')->transaction(function () use ($inviter, $workspace, $email, $normalizedEmail, $role, $token): WorkspaceInvitation {
            $lockedWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($lockedWorkspace->status === 'active' && $lockedWorkspace->archived_at === null, 404);

            $manager = WorkspaceMembership::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->where('user_id', $inviter->getKey())
                ->currentlyActive()
                ->lockForUpdate()
                ->first();
            abort_unless($manager !== null && in_array($manager->role, ['owner', 'admin'], true), 403);

            $matchingUsers = PlatformUser::query()
                ->where(fn ($query) => $query
                    ->where('email_normalized', $normalizedEmail)
                    ->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]))
                ->limit(2)
                ->get();

            if ($matchingUsers->count() > 1) {
                throw ValidationException::withMessages([
                    'email' => __('This email matches more than one platform account and cannot be invited yet.'),
                ]);
            }

            $existingUser = $matchingUsers->first();
            if ($existingUser !== null && WorkspaceMembership::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->where('user_id', $existingUser->getKey())
                ->currentlyActive()
                ->exists()) {
                throw ValidationException::withMessages(['email' => __('This person is already a workspace member.')]);
            }

            WorkspaceInvitation::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->where('email_normalized', $normalizedEmail)
                ->where('status', 'pending')
                ->update(['status' => 'revoked', 'updated_at' => now()]);

            return WorkspaceInvitation::query()->create([
                'workspace_id' => $lockedWorkspace->getKey(),
                'invited_by_user_id' => $inviter->getKey(),
                'email' => trim($email),
                'email_normalized' => $normalizedEmail,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => now()->addDays((int) config('lessbuild.registration.invitation_days', 7)),
            ]);
        }, attempts: 3);

        return new CreatedWorkspaceInvitation($invitation, $token);
    }
}
