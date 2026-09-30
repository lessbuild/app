<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\StartGoogleAnalyticsImport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class StartGoogleAnalyticsImportController
{
    /**
     * Start importing the chosen Google Analytics property and dates into the site.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $import
     * @param  StartGoogleAnalyticsImport  $start
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $import, StartGoogleAnalyticsImport $start): RedirectResponse
    {
        $data = $request->validate([
            'property' => ['required', 'string', 'regex:/^\d{1,20}$/'],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d'],
        ]);
        $back = to_route('analytics.sites.show', [$project, $site->id]);
        try {
            $start->handle($user, $site->imports()->findOrFail($import), $data['property'], CarbonImmutable::parse($data['from'], $site->timezone), CarbonImmutable::parse($data['until'], $site->timezone));
        } catch (RuntimeException $exception) {
            return $back->withErrors(['property' => $exception->getMessage()]);
        }

        return $back->with('status', __('Importing. It can take a few minutes for large sites.'));
    }
}
