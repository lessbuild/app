<?php

namespace App\Core\Services\Projects;

use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Data\Projects\WorkspaceProjectOperationalState;
use App\Core\Models\ProjectProduct;
use Illuminate\Support\Collection;

final class WorkspaceDashboardOperationalFilter
{
    /**
     * @param  Collection<string, ProjectProductSnapshot>  $summaries
     * @param  Collection<string, ProjectSetupStep>  $setupSteps
     * @param  Collection<int, ProjectProduct>  $products
     */
    public function matches(
        WorkspaceProjectOperationalState $state,
        Collection $summaries,
        Collection $setupSteps,
        Collection $products,
        bool $hasFailedConnection,
    ): bool {
        $needsAttention = $hasFailedConnection || $products->contains(
            fn (ProjectProduct $product): bool => in_array($product->status, ['failed', 'error'], true),
        ) || $summaries->contains(
            fn (ProjectProductSnapshot $summary): bool => $summary->state === ProjectProductSnapshotState::Attention,
        );
        $needsSetup = $products->contains(
            fn (ProjectProduct $product): bool => in_array($product->status, ['pending', 'provisioning', 'setting_up'], true),
        ) || $setupSteps->contains(
            fn (ProjectSetupStep $step): bool => $step->state === ProjectSetupStepState::NeedsAction,
        );
        $unavailable = $summaries->contains(
            fn (ProjectProductSnapshot $summary): bool => $summary->state === ProjectProductSnapshotState::Unavailable,
        ) || $setupSteps->contains(
            fn (ProjectSetupStep $step): bool => $step->state === ProjectSetupStepState::Unavailable,
        );

        return match ($state) {
            WorkspaceProjectOperationalState::All => true,
            WorkspaceProjectOperationalState::Attention => $needsAttention,
            WorkspaceProjectOperationalState::Setup => $needsSetup,
            WorkspaceProjectOperationalState::Unavailable => $unavailable,
            WorkspaceProjectOperationalState::Current => $summaries->isNotEmpty()
                && ! $needsAttention
                && ! $needsSetup
                && ! $unavailable
                && $products->every(fn (ProjectProduct $product): bool => $product->status === 'active')
                && $summaries->every(fn (ProjectProductSnapshot $summary): bool => in_array(
                    $summary->state,
                    [ProjectProductSnapshotState::Current, ProjectProductSnapshotState::Empty],
                    true,
                )),
        };
    }
}
