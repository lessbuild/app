<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Support\CsvDownload;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** `GET /api/app/projects/{project}/deploy/repositories/{repository}/webhook-deliveries.csv`. */
final class ExportWebhookDeliveriesController
{
    /**
     * Download the pushes the repository's webhook received, newest first: what happened to each, its commit, the
     * deploy it started and how many paths changed.
     *
     * @param  Project  $project
     * @param  Repository  $repository
     * @return StreamedResponse
     */
    public function __invoke(Project $project, Repository $repository): StreamedResponse
    {
        $rows = (function () use ($repository): Generator {
            foreach (RepositoryWebhookDelivery::query()->where('repository_id', $repository->id)->latest('id')->lazy(200) as $delivery) {
                yield [
                    $delivery->created_at?->toIso8601String(),
                    $delivery->delivery_id,
                    $delivery->status,
                    $delivery->revision,
                    $delivery->commit_message === null ? null : mb_substr(strtok($delivery->commit_message, "\n") ?: '', 0, 200),
                    $delivery->build_id,
                    count($delivery->changed_paths ?? []),
                ];
            }
        })();

        return CsvDownload::stream('push-deliveries-'.$repository->id.'-'.now('UTC')->format('Ymd-His').'.csv', ['received_at', 'delivery', 'status', 'revision', 'commit', 'deploy', 'changed_paths'], $rows);
    }
}
