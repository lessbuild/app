<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\Repository;
use App\Support\CsvDownload;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** `GET /api/app/projects/{project}/deploy/repositories/{repository}/builds.csv`. */
final class ExportRepositoryBuildsController
{
    /**
     * Download a repository's deploys as a spreadsheet, newest first: when, status, what triggered it, the commit, how
     * long it took, and its note. Logs and environment values are left out.
     *
     * @param  Project  $project
     * @param  Repository  $repository
     * @return StreamedResponse
     */
    public function __invoke(Project $project, Repository $repository): StreamedResponse
    {
        $rows = (function () use ($repository): Generator {
            foreach (Build::query()->where('repository_id', $repository->id)->latest('id')->lazy(200) as $build) {
                yield [
                    $build->id,
                    $build->created_at?->toIso8601String(),
                    $build->status,
                    $build->trigger_source,
                    $build->git_ref,
                    $build->revision,
                    $build->commit_message === null ? null : mb_substr(strtok($build->commit_message, "\n") ?: '', 0, 200),
                    $build->started_at !== null && $build->finished_at !== null ? (int) $build->started_at->diffInSeconds($build->finished_at) : null,
                    $build->operator_note,
                ];
            }
        })();

        return CsvDownload::stream('deploys-'.$repository->id.'-'.now('UTC')->format('Ymd-His').'.csv', ['id', 'created_at', 'status', 'trigger', 'ref', 'revision', 'commit', 'duration_seconds', 'note'], $rows);
    }
}
