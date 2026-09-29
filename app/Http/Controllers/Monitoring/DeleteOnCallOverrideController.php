<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteOnCallRecord;
use App\Models\OnCallOverride;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteOnCallOverrideController
{
    /**
     * Remove an override and return to on-call.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $override
     * @param  DeleteOnCallRecord  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $override, DeleteOnCallRecord $delete): RedirectResponse
    {
        $record = OnCallOverride::query()->whereHas('schedule', fn ($query) => $query->where('account_id', $project->account_id))->findOrFail($override);
        $delete->handle($user, $record);

        return to_route('monitoring.on-call', $project)->with('status', __('Cover removed.'));
    }
}
