<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Data\Deploy\RepositoryChangeImpact;
use App\Models\Project;
use App\Models\Repository;
use App\Services\Deploy\RepositoryChangeImpactEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /api/app/projects/{project}/deploy/impact-preview`. */
final class PreviewChangeImpactController
{
    /**
     * Say which of the project's repositories a push changing these paths would deploy, using the same path filters
     * (subdirectory, include and ignore globs) as real push deploys. Nothing is deployed.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  RepositoryChangeImpactEvaluator  $impact
     * @return JsonResponse
     */
    public function __invoke(Request $request, Project $project, RepositoryChangeImpactEvaluator $impact): JsonResponse
    {
        $text = (string) $request->validate(['paths' => ['required', 'string', 'max:20000']])['paths'];
        $paths = array_values(array_unique(array_filter(array_map(fn (string $line): string => trim($line), preg_split('/\R/', $text) ?: []), fn (string $line): bool => $line !== '')));
        abort_if(count($paths) > 500, 422, __('Give at most 500 paths.'));

        $repositories = Repository::query()->where('project_id', $project->id)->with('website')->orderBy('name')->get();

        return response()->json([
            'paths' => count($paths),
            'repositories' => $repositories->map(function (Repository $repository) use ($impact, $paths): array {
                $result = $impact->evaluate($repository, $paths);

                return [
                    'id' => $repository->id,
                    'name' => $repository->name,
                    'branch' => $repository->branch,
                    'website' => $repository->website->url,
                    'pushDeploys' => (bool) $repository->webhook_enabled,
                    'decision' => $result->status,
                    'label' => match ($result->status) {
                        RepositoryChangeImpact::AFFECTED => __('Would deploy'),
                        RepositoryChangeImpact::UNAFFECTED => __('Wouldn’t deploy'),
                        default => __('Would deploy, to be safe'),
                    },
                    'matched' => array_slice($result->matchedPaths, 0, 20),
                ];
            })->values(),
        ]);
    }
}
