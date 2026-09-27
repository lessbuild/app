<?php

declare(strict_types=1);

namespace App\Queries\Deploy;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationReview;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Website;
use App\Services\Deploy\Configuration\ConfigurationOperations;
use Illuminate\Database\Eloquent\Collection;

/** The configuration page's references and history, and the application receipt the API returns. */
final class ConfigurationQuery
{
    /**
     * Reads for the configuration pages and API.
     *
     * @param  ConfigurationOperations  $operations  Brings an application's operations up to date before they're reported.
     */
    public function __construct(private readonly ConfigurationOperations $operations) {}

    /**
     * What the configuration page shows: the websites and repositories a document can bind to, the secret variables it
     * can reference, and the latest reviews.
     *
     * @return array{websites: Collection<int, Website>, repositories: Collection<int, Repository>, secrets: Collection<int, EnvironmentVariable>, reviews: Collection<int, ConfigurationReview>}
     */
    public function page(Project $project): array
    {
        return [
            'websites' => Website::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'repositories' => Repository::query()->where('project_id', $project->id)->with('website')->orderBy('name')->get(),
            'secrets' => EnvironmentVariable::query()->where('is_secret', true)->whereHas('environment', fn ($query) => $query->where('project_id', $project->id))->with('environment')->orderBy('key')->get(),
            'reviews' => ConfigurationReview::query()->where('project_id', $project->id)->with(['requester', 'application'])->latest('id')->limit(15)->get(),
        ];
    }

    /**
     * An application's status and its operations, in the shape the Deployer API returns. Operations are refreshed first,
     * so finished deploys are reflected.
     *
     * @return array<string, mixed> Deployer's receipt: the application's status and its operations.
     */
    public function receipt(ConfigurationApplication $application): array
    {
        $application = $this->operations->refresh($application);

        return [
            'id' => $application->id, 'review_id' => $application->configuration_review_id, 'status' => $application->status,
            'locally_applied_at' => $application->locally_applied_at?->toIso8601String(),
            'operations' => $application->relatedOperations()->orderBy('id')->get()->map(fn (ConfigurationOperation $operation): array => [
                'id' => $operation->id, 'environment_slug' => $operation->environment_slug, 'kind' => $operation->kind, 'status' => $operation->status,
                'build_id' => $operation->build_id, 'attempts' => $operation->attempts, 'failure_code' => $operation->failure_code,
                'completed_at' => $operation->completed_at?->toIso8601String(), 'retry_of_operation_id' => $operation->retry_of_operation_id, 'retry_sequence' => $operation->retry_sequence,
            ])->all(),
        ];
    }
}
