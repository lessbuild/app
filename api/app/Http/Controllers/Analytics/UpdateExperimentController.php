<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\StopExperiment;
use App\Models\AnalyticsExperiment;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateExperimentController
{
    /**
     * Stop or delete an experiment and return to Explore's experiments. Another site's experiments are a 404.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $experiment
     * @param  StopExperiment  $stop
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $experiment, StopExperiment $stop): JsonResponse
    {
        $stop->handle($user, AnalyticsExperiment::query()->where('site_id', $site->id)->findOrFail($experiment), $request->isMethod('DELETE'));

        return response()->json(['redirect' => route('analytics.explore', [$project, 'site' => $site->id, 'tab' => 'experiments'], false), 'message' => $request->isMethod('DELETE') ? __('Experiment deleted.') : __('Experiment stopped.')]);
    }
}
