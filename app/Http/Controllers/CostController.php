<?php

namespace App\Http\Controllers;

use App\Actions\Cost\UpdateInfrastructureBudgetAction;
use App\Http\Requests\UpdateInfrastructureBudgetRequest;
use App\Models\Size;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CostController extends Controller
{
    /**
     * Use workspace entitlements to gate infrastructure-budget controls.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Render workspace server cost estimates, missing prices, recent utilization, and budget availability.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $servers = $organization->servers()
            ->withCount('websites')
            ->with(['provider:id,name', 'metrics' => fn ($query) => $query->latest('recorded_at')->limit(12)])
            ->orderBy('name')->get();
        $prices = Size::query()->whereIn('slug', $servers->pluck('size')->filter()->unique())->pluck('price_monthly', 'slug');
        $rows = $servers->map(function ($server) use ($prices): array {
            $samples = $server->metrics;
            $averageCpu = $samples->whereNotNull('cpu_percent')->avg('cpu_percent');
            $monthly = isset($prices[$server->size]) ? (float) $prices[$server->size] : null;
            $idle = $server->websites_count === 0 || ($samples->count() >= 6 && $averageCpu !== null && $averageCpu < 10);

            return compact('server', 'monthly', 'averageCpu', 'idle');
        });
        $estimated = $rows->sum(fn (array $row): float => $row['monthly'] ?? 0);

        return view('costs.index', [
            'rows' => $rows,
            'estimated' => $estimated,
            'unknownCount' => $rows->whereNull('monthly')->count(),
            'idleCount' => $rows->where('idle', true)->count(),
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
