<?php

namespace App\Http\Livewire;

use App\Actions\Server\CollectServerLogAction;
use App\Jobs\Server\CollectServerMetricsJob;
use App\Jobs\Server\RefreshServerLogJob;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ServerShow extends Component
{
    public string $log = 'apt';

    /**
     * @var array
     */
    protected $queryString = ['log'];

    public Server $server;

    /**
     * Retain the requested server and default unfinished servers to provisioning logs when no log query is supplied.
     */
    public function mount(Server $server): void
    {
        $this->server = $server;

        if (! request()->query->has('log') && $server->provisioning_status !== Server::STATUS_ACTIVE) {
            $this->log = 'provisioning';
        }
    }

    /**
     * Authorize updates and queue the selected server log snapshot when provisioning is complete.
     *
     * An inactive server adds a component validation error and queues no collection job.
     */
    public function refreshLogs(): void
    {
        Gate::authorize('update', $this->server);
        $this->server->refresh();
        $this->log = $this->selectedLogType();

        if ($this->server->provisioning_status !== Server::STATUS_ACTIVE) {
            $this->addError('logs', __('Logs are only available after provisioning finishes.'));

            return;
        }

        $this->server->logSnapshots()->updateOrCreate(
            ['type' => $this->log],
            [
                'status' => ServerLogSnapshot::STATUS_QUEUED,
                'error' => null,
            ],
        );

        RefreshServerLogJob::dispatch($this->server->id, $this->log);
    }

    /**
     * Authorize server updates and queue collection of a new metrics sample.
     */
    public function refreshMetrics(): void
    {
        Gate::authorize('update', $this->server);
        CollectServerMetricsJob::dispatch($this->server->id);
    }

    /**
     * Refresh and authorize server visibility, then render bounded metric history, selected logs, and polling state.
     */
    public function render(): View
    {
        $this->server->refresh();
        Gate::authorize('view', $this->server);
        $this->log = $this->selectedLogType();
        $websites = $this->server->websites()->get();
        $recipes = $this->server->provisioningRecipes();
        $logSnapshot = $this->server->logSnapshots()->where('type', $this->log)->first();
        $logSnapshots = $this->server->logSnapshots()
            ->whereIn('type', CollectServerLogAction::TYPES)
            ->get(['id', 'server_id', 'type', 'status', 'refreshed_at']);
        $logStatusCounts = $logSnapshots->countBy('status');
        $latestLogSnapshot = $logSnapshots
            ->whereNotNull('refreshed_at')
            ->sortByDesc('refreshed_at')
            ->first();
        $logs = $logSnapshot?->log === null ? [] : explode(PHP_EOL, $logSnapshot->log);
        $shouldPoll = ! in_array($this->server->provisioning_status, [Server::STATUS_ACTIVE, Server::STATUS_FAILED], true)
            || in_array($logSnapshot?->status, [ServerLogSnapshot::STATUS_QUEUED, ServerLogSnapshot::STATUS_REFRESHING], true);

        return view('livewire.scenes.servers.show', [
            'websites' => $websites,
            'recipes' => $recipes,
            'logs' => $logs,
            'logSnapshot' => $logSnapshot,
            'logMetrics' => [
                'ready' => $logStatusCounts->get(ServerLogSnapshot::STATUS_READY, 0),
                'queued' => $logStatusCounts->get(ServerLogSnapshot::STATUS_QUEUED, 0),
                'refreshing' => $logStatusCounts->get(ServerLogSnapshot::STATUS_REFRESHING, 0),
                'failed' => $logStatusCounts->get(ServerLogSnapshot::STATUS_FAILED, 0),
                'missing' => count(CollectServerLogAction::TYPES) - $logSnapshots->count(),
                'latest_at' => $latestLogSnapshot?->refreshed_at,
            ],
            'shouldPoll' => $shouldPoll,
            'metricHistory' => $this->server->metrics()->where('recorded_at', '>=', now()->subDay())->latest('recorded_at')->limit(288)->get()->reverse()->values(),
            'latestMetric' => $this->server->metrics()->latest('recorded_at')->first(),
        ])->layout('components.layouts.app');
    }

    /**
     * Return the requested supported log type, falling back to apt for unrecognized component input.
     */
    private function selectedLogType(): string
    {
        return in_array($this->log, CollectServerLogAction::TYPES, true) ? $this->log : 'apt';
    }
}
