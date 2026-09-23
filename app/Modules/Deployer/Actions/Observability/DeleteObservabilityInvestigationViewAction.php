<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\ObservabilityInvestigationView;

class DeleteObservabilityInvestigationViewAction
{
    /** Remove one named investigation view after its policy has authorized the request. */
    public function handle(ObservabilityInvestigationView $view): void
    {
        $view->delete();
    }
}
