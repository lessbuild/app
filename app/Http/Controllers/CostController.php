<?php

namespace App\Http\Controllers;

use App\Actions\Cost\UpdateInfrastructureBudgetAction;
use App\Http\Requests\UpdateInfrastructureBudgetRequest;
use App\Services\Entitlements;
use App\Services\InfrastructureCostQuery;
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
    ) {}

    /**
     * Render workspace server cost estimates, missing prices, recent utilization, and budget availability.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $report = $this->costs->for($organization);

        return view('costs.index', [
            'rows' => $report->rows,
            'estimated' => $report->estimated,
            'unknownCount' => $report->unknownCount,
            'idleCount' => $report->idleCount,
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
