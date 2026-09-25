<?php

namespace App\Modules\Analytics\Livewire\Dashboard;

use App\Modules\Analytics\Actions\Workspaces\EnsurePersonalWorkspace;
use App\Modules\Analytics\Queries\Reporting\OverviewReport;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class Overview extends Component
{
    #[Url(as: 'site', nullable: true)]
    public ?int $selectedSiteId = null;

    #[Url(as: 'days', except: 30)]
    public int $days = 30;

    #[Url(as: 'path', except: '')]
    public string $pathFilter = '';

    #[Url(as: 'source', except: '')]
    public string $sourceFilter = '';

    #[Url(as: 'campaign', except: '')]
    public string $campaignFilter = '';

    #[Url(as: 'device', except: '')]
    public string $deviceFilter = '';

    public function mount(EnsurePersonalWorkspace $ensureWorkspace): void
    {
        $workspace = $ensureWorkspace->handle(auth()->user(), $this->selectedSiteId);
        $this->selectedSiteId ??= app(AnalyticsWorkspaceAccess::class)->sitesQuery(auth()->user(), $workspace)->value('id');
    }

    public function updatedDays(): void
    {
        $this->days = min(max((int) $this->days, 7), 395);
    }

    public function clearFilters(): void
    {
        $this->pathFilter = '';
        $this->sourceFilter = '';
        $this->campaignFilter = '';
        $this->deviceFilter = '';
    }

    public function render(OverviewReport $report): View
    {
        $workspace = app(EnsurePersonalWorkspace::class)->handle(auth()->user(), $this->selectedSiteId);
        $sites = app(AnalyticsWorkspaceAccess::class)->sitesQuery(auth()->user(), $workspace)->orderBy('name')->get();
        $site = $sites->firstWhere('id', $this->selectedSiteId) ?? $sites->first();

        if ($site && $this->selectedSiteId !== $site->id) {
            $this->selectedSiteId = $site->id;
        }

        return view('analytics::livewire.dashboard.overview', [
            'workspace' => $workspace,
            'sites' => $sites,
            'site' => $site,
            'releaseAnnotations' => $site?->releaseAnnotations()->limit(5)->get() ?? collect(),
            'incidentAnnotations' => $site?->incidentAnnotations()->limit(5)->get() ?? collect(),
            'summary' => $site ? $report->for($site, $this->days, [
                'path' => $this->pathFilter ?: null,
                'source' => $this->sourceFilter ?: null,
                'campaign' => $this->campaignFilter ?: null,
                'device' => $this->deviceFilter ?: null,
            ]) : null,
        ])->layout('analytics::layouts.app', ['title' => 'Overview']);
    }
}
