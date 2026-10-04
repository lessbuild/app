<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\StartGoogleAnalyticsImport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $import, StartGoogleAnalyticsImport $start): JsonResponse
    {
        $data = $request->validate([
            'property' => ['required', 'string', 'regex:/^\d{1,20}$/'],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d'],
        ]);
        try {
            $start->handle($user, $site->imports()->findOrFail($import), $data['property'], CarbonImmutable::parse($data['from'], $site->timezone), CarbonImmutable::parse($data['until'], $site->timezone));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['property' => $exception->getMessage()]);
        }

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site->id], false), 'message' => __('Importing. It can take a few minutes for large sites.')]);
    }
}
