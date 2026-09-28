<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\RequestExport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RequestExportController
{
    /**
     * Starts a CSV export of a report with its filters and goes to the export's page to wait for it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  RequestExport  $export
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, RequestExport $export): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'in:7,30,90,365'],
            'path' => ['nullable', 'string', 'max:2048'],
            'source' => ['nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:150'],
            'device' => ['nullable', 'string', 'max:32'],
        ]);
        $token = $export->handle($user, $site, $validated);

        return to_route('analytics.exports.show', [$project, $token]);
    }
}
