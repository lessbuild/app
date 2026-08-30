<?php

namespace App\Http\Livewire;

use App\Actions\Server\CollectServerLogAction;
use App\Jobs\Server\RefreshServerLogJob;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ServerShow extends Component
{
    public string $log = 'apt';

    /**
     * @var array
     */
    protected $queryString = ['log'];

    public Server $server;

    public function mount(Server $server): void
    {
        $this->server = $server;

        if (! request()->query->has('log') && $server->provisioning_status !== Server::STATUS_ACTIVE) {
            $this->log = 'provisioning';
        }
    }

    public function refreshLogs(): void
    {
        abort_unless((int) auth()->id() === (int) $this->server->user_id, 403);
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

    public function render(): View
    {
        $this->server->refresh();
        abort_unless((int) auth()->id() === (int) $this->server->user_id, 403);
        $this->log = $this->selectedLogType();
        $websites = $this->server->websites()->get();
        $recipes = $this->server->recipes()->get();
        $logSnapshot = $this->server->logSnapshots()->where('type', $this->log)->first();
        $logs = $logSnapshot?->log === null ? [] : explode(PHP_EOL, $logSnapshot->log);
        $shouldPoll = ! in_array($this->server->provisioning_status, [Server::STATUS_ACTIVE, Server::STATUS_FAILED], true)
            || in_array($logSnapshot?->status, [ServerLogSnapshot::STATUS_QUEUED, ServerLogSnapshot::STATUS_REFRESHING], true);

        return view('livewire.scenes.servers.show', [
            'websites' => $websites,
            'recipes' => $recipes,
            'logs' => $logs,
            'logSnapshot' => $logSnapshot,
            'shouldPoll' => $shouldPoll,
        ])->layout('components.layouts.app');
    }

    private function selectedLogType(): string
    {
        return in_array($this->log, CollectServerLogAction::TYPES, true) ? $this->log : 'apt';
    }
}
