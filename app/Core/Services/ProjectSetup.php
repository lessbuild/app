<?php

namespace App\Core\Services;

use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Collection;
use PDOException;

final class ProjectSetup
{
    public function __construct(private readonly ProjectSetupRegistry $providers) {}

    /** @param list<string> $products
     * @return Collection<string, ProjectSetupStep>
     */
    public function forProject(PlatformUser $user, Project $project, array $products): Collection
    {
        $steps = collect();

        foreach (array_unique($products) as $product) {
            $provider = $this->providers->get($product);

            if ($provider === null) {
                continue;
            }

            try {
                foreach ($provider->steps($user, $project) as $step) {
                    $steps->put($step->id, $step);
                }
            } catch (LostConnectionException|PDOException) {
                $steps->put($product.'.unavailable', new ProjectSetupStep(
                    id: $product.'.unavailable',
                    product: $product,
                    title: __('Setup status unavailable'),
                    detail: __('This application’s data is temporarily unavailable. Your existing setup has not been changed.'),
                    state: ProjectSetupStepState::Unavailable,
                ));
            }
        }

        return $steps;
    }
}
