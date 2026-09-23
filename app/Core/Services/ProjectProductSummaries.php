<?php

namespace App\Core\Services;

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
    public function forProject(PlatformUser $user, Project $project, array $products): Collection
    {
        $summaries = collect();

        foreach (array_unique($products) as $product) {
            $provider = $this->providers->get($product);

            if ($provider === null) {
                continue;
            }

            try {
                $summary = $provider->summarize($user, $project);
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
}
