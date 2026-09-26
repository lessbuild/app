<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Modules\Monitor\Models\IssueDigestPreference;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\IssueDigestHistory;
use App\Modules\Monitor\Services\SaveIssueDigestPreference;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request, CurrentWorkspace $currentWorkspace, WorkspacePlanLimits $limits, IssueDigestHistory $history): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $preference = IssueDigestPreference::query()
            ->whereBelongsTo($workspace)
            ->whereBelongsTo($user)
            ->first();

        return view('monitor::settings.notifications', [
            'workspace' => $workspace,
            'preference' => $preference,
            'digestEnabled' => $preference?->enabled ?? ($user->id === $workspace->owner_id),
            'digestAvailable' => $limits->issueDigestEnabled($workspace),
            'isWorkspaceOwner' => $user->id === $workspace->owner_id,
            'deliveries' => $history->forRecipient($workspace, $user),
        ]);
    }

    public function update(UpdateNotificationPreferenceRequest $request, CurrentWorkspace $currentWorkspace, SaveIssueDigestPreference $save): RedirectResponse
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $save->save($workspace, $user, (bool) ($request->validated()['enabled'] ?? false));

        return to_route('monitor.settings.notifications')->with('status', 'Notification preferences updated.');
    }
}
