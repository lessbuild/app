<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveExperiment;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreExperimentController
{
    /**
     * Start an experiment and return to Explore's experiments.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  SaveExperiment  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, SaveExperiment $save): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'key' => ['required', 'string', 'max:60'], 'variants' => ['required', 'string', 'max:400'], 'goal_id' => ['nullable', 'integer']]);
        $save->handle($user, $site, $data['name'], $data['key'], array_map(trim(...), explode(',', $data['variants'])), isset($data['goal_id']) ? (int) $data['goal_id'] : null);

        return response()->json(['redirect' => route('analytics.explore', [$project, 'site' => $site->id, 'tab' => 'experiments'], false), 'message' => __('Experiment started.')]);
    }
}
