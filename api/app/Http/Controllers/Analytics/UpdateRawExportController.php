<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SetRawExport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateRawExportController
{
    /**
     * Choose where a site's raw events are exported each day, or stop exporting, and return to its settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  SetRawExport  $set
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, SetRawExport $set): RedirectResponse
    {
        $request->validate(['export_bucket_id' => ['nullable', 'integer'], 'export_prefix' => ['nullable', 'string', 'max:200']]);
        $set->handle($user, $site, $request->filled('export_bucket_id') ? $request->integer('export_bucket_id') : null, $request->string('export_prefix')->toString());

        return to_route('analytics.sites.show', [$project, $site->id])->with('status', $request->filled('export_bucket_id') ? __('Raw events will be exported each night.') : __('Raw export stopped.'));
    }
}
