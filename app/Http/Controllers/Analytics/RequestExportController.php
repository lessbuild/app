<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\RequestExport;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RequestExportController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $site, ProjectSitesQuery $sites, RequestExport $export): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'in:7,30,90,365'],
            'path' => ['nullable', 'string', 'max:2048'],
            'source' => ['nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:150'],
            'device' => ['nullable', 'string', 'max:32'],
        ]);
        $token = $export->handle($user, $sites->find($project, $site), $validated);

        return to_route('analytics.exports.show', [$project, $token]);
    }
}
