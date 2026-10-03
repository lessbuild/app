<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\SetFindingStatus;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateSecurityFindingController
{
    /**
     * Ignore a finding with a reason, or reopen it, and go back. Another project's findings are a 404.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $finding
     * @param  SetFindingStatus  $set
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, int $finding, SetFindingStatus $set): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:ignored,open'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $set->handle($user, SecurityFinding::query()->where('project_id', $project->id)->findOrFail($finding), $data['status'] === 'ignored', $data['reason'] ?? null);

        return back()->with('status', $data['status'] === 'ignored' ? __('Finding ignored.') : __('Finding reopened.'));
    }
}
