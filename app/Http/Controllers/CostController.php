<?php

namespace App\Http\Controllers;

use App\Models\Size;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CostController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

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

    public function update(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($organization, 'cost_controls');
        $data = $request->validate(['monthly_infrastructure_budget' => ['nullable', 'numeric', 'between:1,1000000']]);
        $organization->update(['monthly_infrastructure_budget' => $data['monthly_infrastructure_budget'] ?? null]);

        return back()->with('success', __('Infrastructure budget updated.'));
    }
}
