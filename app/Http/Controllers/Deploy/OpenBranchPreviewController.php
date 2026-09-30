<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\OpenBranchPreview;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OpenBranchPreviewController
{
    /**
     * Open a preview of a branch and return to the previews.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  OpenBranchPreview  $open
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, OpenBranchPreview $open): RedirectResponse
    {
        $data = $request->validate(['repository_id' => ['required', 'integer'], 'branch' => ['required', 'string', 'max:200'], 'days' => ['required', 'integer', 'between:1,30']]);
        $repository = Repository::query()->where('project_id', $project->id)->findOrFail((int) $data['repository_id']);
        $preview = $open->handle($user, $repository, $data['branch'], (int) $data['days']);

        return to_route('deploy.previews', $project)->with('status', __('Preview of :branch is on its way: :url', ['branch' => $preview->source_branch, 'url' => $preview->url]));
    }
}
