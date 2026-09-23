<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;

final class AcceptWorkspaceInvitation
{
    public function __construct(private readonly WorkspacePlanLimits $limits, private readonly RecordAuditLog $audit) {}

    public function accept(User $user, string $token): Workspace
    {
        return DB::connection('monitor')->transaction(function () use ($user, $token): Workspace {
            $invitation = WorkspaceInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->firstOrFail();

            abort_unless($user->hasVerifiedEmail() && $user->email === $invitation->email, 404);

            $workspace = Workspace::query()->lockForUpdate()->findOrFail($invitation->workspace_id);
            $invitation = WorkspaceInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_if($invitation->accepted_at !== null || ! $invitation->expires_at->isFuture(), 410, 'This invitation is no longer available.');

            if (! $workspace->members()->whereKey($user->id)->exists()) {
                $this->limits->assertSeatCapacity($workspace);
                $workspace->members()->attach($user, ['role' => $invitation->role]);
            }

            $invitation->update(['accepted_at' => now()]);
            $this->audit->record($workspace, $user, 'invitation.accepted', $invitation, ['label' => $user->name, 'role' => $invitation->role]);

            return $workspace;
        });
    }
}
