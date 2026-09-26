<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Cost\UpdateInfrastructureBudgetAction;
use App\Modules\Deployer\Http\Requests\UpdateInfrastructureBudgetRequest;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\InfrastructureCostQuery;
use App\Modules\Deployer\Services\PreviewUsageQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CostController extends Controller
{
    /**
     * Use workspace entitlements for budget controls and a bounded cost query for reporting.
     */
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly InfrastructureCostQuery $costs,
        private readonly PreviewUsageQuery $previewUsage,
    ) {}

    /**
     * Render workspace server cost estimates, missing prices, recent utilization, and budget availability.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $report = $this->costs->for($organization);
        $previewUsage = $this->previewUsage->for($organization);

        return view('costs.index', [
            'rows' => $report->rows,
            'estimated' => $report->estimated,
            'unknownCount' => $report->unknownCount,
            'idleCount' => $report->idleCount,
            'previewUsage' => $previewUsage,
            'budget' => $organization->monthly_infrastructure_budget,
            'canManage' => $organization->permits($request->user(), 'manage')
                && $this->entitlements->allows($organization, 'cost_controls'),
            'featureAvailable' => $this->entitlements->allows($organization, 'cost_controls'),
        ]);
    }

    /**
     * Require entitled workspace management access, validate a nullable monthly budget, save it, and redirect back.
     */
    public function update(
        UpdateInfrastructureBudgetRequest $request,
        UpdateInfrastructureBudgetAction $updateBudget,
    ): RedirectResponse {
        $organization = $request->user()->currentOrganization;
        $updateBudget->handle($organization, $request->validated());

        return back()->with('success', __('Infrastructure budget updated.'));
    }
}
