<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateSiteCollectionController
{
    /**
     * Pause collecting a site's visits, or start again. While paused the snippet's data is turned away and existing
     * reports stay available.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  RecordAuditEntry  $audit
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, RecordAuditEntry $audit): JsonResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        if ($site->collection_enabled !== $enabled) {
            $site->forceFill(['collection_enabled' => $enabled])->save();
            $audit->handle($enabled ? AuditAction::AnalyticsCollectionResumed : AuditAction::AnalyticsCollectionPaused, $user, $project->account_id, ['site' => $site->name], $project->id);
        }

        return response()->json(['message' => $enabled ? __('Collecting again.') : __('Collection paused. Reports stay available.')]);
    }
}
