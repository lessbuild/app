<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ReviewBuild;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ReviewBuildController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Build $build, ReviewBuild $review): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:1000']]);
        $review->handle($user, $build, $data['decision'] === 'approve', $data['note'] ?? null);

        return to_route('deploy.builds.show', [$project, $build->id])->with('status', $data['decision'] === 'approve' ? __('Approved; the deploy is starting.') : __('Deploy rejected.'));
    }
}
