<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\PromoteBuild;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PromoteBuildController
{
    /**
     * Promotes a deploy's commit to another of the project's environments, saying whether it's running or waiting for
     * approval.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Build $build, PromoteBuild $promote): RedirectResponse
    {
        $request->validate(['environment_id' => ['required', 'string', 'max:26'], 'note' => ['nullable', 'string', 'max:2000']]);
        $target = $project->environments()->findOrFail($request->string('environment_id')->toString());
        $promoted = $promote->handle($user, $build, $target, $request->filled('note') ? $request->string('note')->toString() : null);

        return to_route('deploy.builds.show', [$project, $promoted->id])->with('status', $promoted->status === Build::STATUS_AWAITING_APPROVAL ? __('Promotion waiting for approval.') : __('Promoting to :environment.', ['environment' => $target->name]));
    }
}
