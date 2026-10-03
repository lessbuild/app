<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveFunnel;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreFunnelController
{
    /**
     * Add a funnel to the site and return to its funnels.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  SaveFunnel  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, SaveFunnel $save): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'steps' => ['required', 'array', 'max:6'], 'steps.*' => ['array']]);
        /** @var list<array{kind?: mixed, match?: mixed, value?: mixed}> $steps */
        $steps = array_values($data['steps']);
        $save->handle($user, $site, null, (string) $data['name'], $steps);

        return to_route('analytics.funnels', [$project, 'site' => $site->id])->with('status', __('Funnel added.'));
    }
}
