<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectEnvironmentAwareSummaryProvider;
use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Collection;
use PDOException;

final class ProjectProductSummaries
{
    public function __construct(private readonly ProjectProductSummaryRegistry $providers) {}

    /** @param list<string> $products
     * @return Collection<string, ProjectProductSnapshot>
     */
    public function forProject(
        PlatformUser $user,
        Project $project,
        array $products,
        ?ProjectEnvironmentContext $environmentContext = null,
    ): Collection {
        $summaries = collect();

        foreach (array_unique($products) as $product) {
            $provider = $this->providers->get($product);

            try {
                if ($environmentContext?->isUnavailable()) {
                    $summary = $this->unavailableEnvironmentSummary();
                } elseif ($environmentContext?->isSelected()) {
                    $summary = $provider instanceof ProjectEnvironmentAwareSummaryProvider && $environmentContext->environment !== null
                        ? $provider->summarizeForEnvironment($user, $project, $environmentContext->environment)
                        : $this->unavailableEnvironmentSummary($environmentContext->name());
                } elseif ($provider !== null) {
                    $summary = $provider->summarize($user, $project);
                } else {
                    continue;
                }
            } catch (LostConnectionException|PDOException) {
                $summary = new ProjectProductSnapshot(
                    title: __('Product data'),
                    detail: __('This application’s data is temporarily unavailable.'),
                    state: ProjectProductSnapshotState::Unavailable,
                );
            }

            if ($summary !== null) {
                $summaries->put($product, $summary);
            }
        }

        return $summaries;
    }

    private function unavailableEnvironmentSummary(?string $environment = null): ProjectProductSnapshot
    {
        return new ProjectProductSnapshot(
            title: __('Environment activity unavailable'),
            detail: $environment === null
                ? __('The selected environment is unavailable. Choose an active environment from this project before relying on app activity.')
                : __('No authorized app environment is mapped to :environment, or the mapping is unavailable.', ['environment' => $environment]),
            state: ProjectProductSnapshotState::Unavailable,
        );
    }
}
