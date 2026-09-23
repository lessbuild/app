<?php

namespace App\Core\Services\Workspaces;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AcceptWorkspaceInvitation
{
    public function __construct(private readonly ResolveWorkspaceInvitation $invitations) {}

    public function handle(PlatformUser $user, string $token): Workspace
    {
        return DB::connection('core')->transaction(function () use ($user, $token): Workspace {
            $invitation = $this->invitations->findValid($token, lockForUpdate: true);
            abort_unless($invitation !== null, 404);

            $userEmail = Str::lower(trim((string) ($user->email_normalized ?: $user->email)));
            if ($userEmail === '' || ! hash_equals((string) $invitation->email_normalized, $userEmail)) {
                throw ValidationException::withMessages([
                    'invitation' => __('Sign in with the email address that received this workspace invitation.'),
                ]);
            }

            abort_unless($user->status === 'active', 403);

            $workspace = Workspace::query()
                ->whereKey($invitation->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($workspace->status === 'active' && $workspace->archived_at === null, 404);

            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                $workspace->memberships()->create([
                    'user_id' => $user->getKey(),
                    'role' => $invitation->role,
                    'status' => 'active',
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'invited_at' => $invitation->created_at,
                    'joined_at' => now(),
                ]);
            } elseif (! $membership->currentlyActive()) {
                $membership->forceFill([
                    'role' => $invitation->role,
                    'status' => 'active',
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'invited_at' => $invitation->created_at,
                    'joined_at' => now(),
                    'expires_at' => null,
                    'revoked_at' => null,
                ])->save();
            }

            $invitation->forceFill([
                'status' => 'accepted',
                'accepted_at' => now(),
            ])->save();

            return $workspace;
        }, attempts: 3);
    }
}
