<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RunEnvironmentRecipesNow;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RunEnvironmentRecipesController
{
    /**
     * Run the environment's recipes on its servers and say where they're queued and which servers were busy.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  RunEnvironmentRecipesNow  $run
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, RunEnvironmentRecipesNow $run): RedirectResponse
    {
        $result = $run->handle($user, $environment);
        $message = trans_choice('Queued on :count server.|Queued on :count servers.', $result['queued']);
        if ($result['busy'] > 0) {
            $message .= ' '.trans_choice(':count server was busy with another command; run it again shortly.|:count servers were busy with another command; run them again shortly.', $result['busy']);
        }

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'recipes'])->with('status', $message);
    }
}
