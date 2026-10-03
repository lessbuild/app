<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SetEnvironmentRuntime;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateEnvironmentRuntimeController
{
    /**
     * Hibernate or wake an environment now and return to its Automation tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SetEnvironmentRuntime  $set
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SetEnvironmentRuntime $set): RedirectResponse
    {
        /** @var array{state: 'running'|'hibernated'} $data */
        $data = $request->validate(['state' => ['required', 'in:running,hibernated']]);
        $set->handle($user, $environment, $data['state']);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'automation'])
            ->with('status', $data['state'] === 'hibernated' ? __('Hibernating. Its websites go into maintenance mode shortly.') : __('Waking up. Workers start shortly.'));
    }
}
