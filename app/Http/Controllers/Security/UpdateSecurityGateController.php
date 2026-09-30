<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\SetSecurityGate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateSecurityGateController
{
    /**
     * Set an environment's deploy gate and return to the Security overview. Another project's environments are a 404.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $environment
     * @param  SetSecurityGate  $set
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $environment, SetSecurityGate $set): RedirectResponse
    {
        $data = $request->validate(['security_gate' => ['nullable', 'in:critical,high']]);
        $set->handle($user, $project->environments()->findOrFail($environment), $data['security_gate'] ?? null);

        return to_route('security.overview', $project)->with('status', __('Deploy gate saved.'));
    }
}
