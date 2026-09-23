<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\StoreWorkspaceInvitationRequest;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Models\WorkspaceInvitation;
use App\Modules\Monitor\Notifications\WorkspaceInvitationNotification;
use App\Modules\Monitor\Services\AcceptWorkspaceInvitation;
use App\Modules\Monitor\Services\RecordAuditLog;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceInvitationController extends Controller
{
    public function store(StoreWorkspaceInvitationRequest $request, Workspace $workspace, WorkspacePlanLimits $limits, RecordAuditLog $audit): RedirectResponse
    {
        $token = Str::random(64);
        $validated = $request->validated();
        $invitation = DB::connection('monitor')->transaction(function () use ($workspace, $validated, $token, $limits, $request, $audit): WorkspaceInvitation {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::authorize('update', $workspace);

            if ($workspace->members()->where('email', $validated['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'This person is already a workspace member.']);
            }

            $hasPendingInvitation = $workspace->invitations()
                ->where('email', $validated['email'])
                ->whereNull('accepted_at')
                ->exists();

            if (! $hasPendingInvitation) {
                $limits->assertSeatCapacity($workspace);
            }

            $invitation = $workspace->invitations()->updateOrCreate(
                ['email' => $validated['email']],
                [
                    'role' => $validated['role'],
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addDays(7),
                    'accepted_at' => null,
                ],
            );
            $audit->record($workspace, $request->user(), 'invitation.sent', $invitation, ['label' => 'Workspace invitation', 'role' => $validated['role']]);

            return $invitation;
        });

        Notification::route('mail', $invitation->email)->notify(new WorkspaceInvitationNotification($token));

        return to_route('monitor.settings.team')->with('status', 'Invitation sent. The link expires in seven days.');
    }

    public function show(Request $request, string $token): View
    {
        $invitation = WorkspaceInvitation::query()->with('workspace')
            ->where('token_hash', hash('sha256', $token))->firstOrFail();

        abort_unless($invitation->email === $request->user()->email, 404);
        abort_if($invitation->accepted_at !== null || ! $invitation->expires_at->isFuture(), 410, 'This invitation is no longer available.');

        return view('monitor::workspaces.invitation', ['invitation' => $invitation, 'token' => $token]);
    }

    public function update(Request $request, string $token, AcceptWorkspaceInvitation $acceptInvitation): RedirectResponse
    {
        $workspace = $acceptInvitation->accept($request->user(), $token);
        $request->session()->put('workspace_id', $workspace->id);

        return to_route('monitor.dashboard')->with('status', 'You joined the workspace.');
    }

    public function destroy(Workspace $workspace, WorkspaceInvitation $invitation, RecordAuditLog $audit): RedirectResponse
    {
        DB::connection('monitor')->transaction(function () use ($workspace, $invitation, $audit): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::authorize('update', $workspace);
            $invitation = $workspace->invitations()->lockForUpdate()->findOrFail($invitation->id);
            $role = $invitation->role;
            $invitation->delete();
            $audit->record($workspace, request()->user(), 'invitation.revoked', $invitation, ['label' => 'Workspace invitation', 'role' => $role]);
        });

        return to_route('monitor.settings.team')->with('status', 'Invitation revoked.');
    }
}
