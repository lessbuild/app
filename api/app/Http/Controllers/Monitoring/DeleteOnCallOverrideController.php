<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteOnCallRecord;
use App\Models\OnCallOverride;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteOnCallOverrideController
{
    /**
     * Remove an override and return to on-call.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $override
     * @param  DeleteOnCallRecord  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $override, DeleteOnCallRecord $delete): JsonResponse
    {
        $record = OnCallOverride::query()->whereHas('schedule', fn ($query) => $query->where('account_id', $project->account_id))->findOrFail($override);
        $delete->handle($user, $record);

        return response()->json(['redirect' => route('monitoring.on-call', $project, false), 'message' => __('Cover removed.')]);
    }
}
