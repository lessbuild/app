<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectEnvironmentAwareSetupProvider;
use App\Core\Data\Projects\ProjectEnvironmentContext;
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
    public function forProject(
        PlatformUser $user,
        Project $project,
        array $products,
        ?ProjectEnvironmentContext $environmentContext = null,
    ): Collection {
        $steps = collect();

        foreach (array_unique($products) as $product) {
            $provider = $this->providers->get($product);

            try {
                if ($environmentContext?->isUnavailable()) {
                    $productSteps = [$this->unavailableEnvironmentStep($product)];
                } elseif ($environmentContext?->isSelected()) {
                    $productSteps = $provider instanceof ProjectEnvironmentAwareSetupProvider && $environmentContext->environment !== null
                        ? $provider->stepsForEnvironment($user, $project, $environmentContext->environment)
                        : [$this->unavailableEnvironmentStep($product, $environmentContext->name())];
                } elseif ($provider !== null) {
                    $productSteps = $provider->steps($user, $project);
                } else {
                    continue;
                }

                foreach ($productSteps as $step) {
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

    private function unavailableEnvironmentStep(string $product, ?string $environment = null): ProjectSetupStep
    {
        return new ProjectSetupStep(
            id: $product.'.environment-unavailable',
            product: $product,
            title: __('Environment setup unavailable'),
            detail: $environment === null
                ? __('The selected environment is unavailable. Choose an active environment from this project before relying on setup status.')
                : __('No authorized app environment is mapped to :environment, or the mapping is unavailable.', ['environment' => $environment]),
            state: ProjectSetupStepState::Unavailable,
            contextName: $environment,
            contextLabel: $environment === null ? null : __('Environment'),
        );
    }
}
