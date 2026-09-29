<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\DecideVariableChange;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DecideVariableChangeController
{
    /**
     * Approve (applying it) or reject a waiting variable change and return to the Variables tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  int  $change
     * @param  DecideVariableChange  $decide
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, int $change, DecideVariableChange $decide): RedirectResponse
    {
        $approve = $request->validate(['decision' => ['required', 'in:approve,reject']])['decision'] === 'approve';
        $decide->handle($user, $environment->pendingVariableChanges()->findOrFail($change), $approve);

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'variables'])->with('status', $approve ? __('Approved and applied.') : __('Rejected.'));
    }
}
