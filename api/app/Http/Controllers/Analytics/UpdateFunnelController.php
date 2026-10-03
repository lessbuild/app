<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveFunnel;
use App\Models\AnalyticsFunnel;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateFunnelController
{
    /**
     * Save changes to a funnel and return to the site's funnels.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $funnel
     * @param  SaveFunnel  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $funnel, SaveFunnel $save): RedirectResponse
    {
        $record = AnalyticsFunnel::query()->where('site_id', $site->id)->findOrFail($funnel);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'steps' => ['required', 'array', 'max:6'], 'steps.*' => ['array']]);
        /** @var list<array{kind?: mixed, match?: mixed, value?: mixed}> $steps */
        $steps = array_values($data['steps']);
        $save->handle($user, $site, $record, (string) $data['name'], $steps);

        return to_route('analytics.funnels', [$project, 'site' => $site->id])->with('status', __('Funnel saved.'));
    }
}
