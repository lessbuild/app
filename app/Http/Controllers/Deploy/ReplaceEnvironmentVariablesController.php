<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ReplaceEnvironmentVariables;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ReplaceEnvironmentVariablesController
{
    /**
     * Replaces an environment's variables with the pasted `.env` text.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  ReplaceEnvironmentVariables  $replace
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, ReplaceEnvironmentVariables $replace): RedirectResponse
    {
        $request->validate(['variables' => ['present', 'nullable', 'string', 'max:200000']]);
        $count = $replace->handle($user, $environment, (string) $request->input('variables', ''));

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'])->with('status', trans_choice(':count variable set.|:count variables set.', $count));
    }
}
