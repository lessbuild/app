<?php

namespace App\Actions\Observability;

use App\Models\ObservabilityInvestigationView;

class DeleteObservabilityInvestigationViewAction
{
    /** Remove one named investigation view after its policy has authorized the request. */
    public function handle(ObservabilityInvestigationView $view): void
    {
        $view->delete();
    }
}
