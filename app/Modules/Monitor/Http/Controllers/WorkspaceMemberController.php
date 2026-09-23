<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeIncident;
use App\Modules\Monitor\Services\ChangeIssue;
use App\Modules\Monitor\Services\RecordAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkspaceMemberController extends Controller
{
    public function update(Request $request, Workspace $workspace, User $member, ChangeIssue $issues, ChangeIncident $incidents, RecordAuditLog $audit): RedirectResponse
    {
        Gate::authorize('update', $workspace);
        $validated = $request->validate(['role' => ['required', Rule::in(Workspace::ASSIGNABLE_ROLES)]]);

        DB::connection('monitor')->transaction(function () use ($workspace, $member, $validated, $request, $issues, $incidents, $audit): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::authorize('update', $workspace);
            abort_unless($workspace->members()->whereKey($member->id)->exists(), 404);
            abort_if($workspace->owner_id === $member->id, 403, 'The workspace owner cannot be demoted.');
            $before = $workspace->members()->whereKey($member->id)->firstOrFail()->pivot->role;
            $workspace->members()->updateExistingPivot($member->id, ['role' => $validated['role']]);

            if ($before !== $validated['role']) {
                $audit->record($workspace, $request->user(), 'member.role_updated', $member, ['label' => $member->name, 'before' => $before, 'after' => $validated['role']]);
            }

            if ($validated['role'] === 'viewer') {
                $issues->unassignMember($workspace, $member, $request->user());
                $incidents->unassignMember($workspace, $member, $request->user());
            }
        });

        return to_route('monitor.settings.team')->with('status', 'Member role updated.');
    }

    public function destroy(Request $request, Workspace $workspace, User $member, ChangeIssue $issues, ChangeIncident $incidents, RecordAuditLog $audit): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        DB::connection('monitor')->transaction(function () use ($workspace, $member, $request, $issues, $incidents, $audit): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::authorize('update', $workspace);
            abort_unless($workspace->members()->whereKey($member->id)->exists(), 404);
            abort_if($workspace->owner_id === $member->id, 403, 'The workspace owner cannot be removed.');
            $role = $workspace->members()->whereKey($member->id)->firstOrFail()->pivot->role;
            $workspace->members()->detach($member->id);
            $audit->record($workspace, $request->user(), 'member.removed', $member, ['label' => $member->name, 'role' => $role]);
            $issues->unassignMember($workspace, $member, $request->user());
            $incidents->unassignMember($workspace, $member, $request->user());
        });

        return to_route('monitor.settings.team')->with('status', 'Member removed.');
    }
}
