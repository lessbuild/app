<?php

namespace App\Http\Livewire;

use App\Models\Server;
use App\Services\Runner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Component;

class ServerShow extends Component
{
    /**
     * @var string
     */
    public string $log = 'apt';

    /**
     * @var array
     */
    protected $queryString = ['log'];

    /**
     * @var \App\Models\Server
     */
    public Server $server;

    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Services\Runner $runner
     * @return \Illuminate\Contracts\View\View
     *
     * @throws \Exception
     */
    public function render(Request $request, Runner $runner): View
    {
        $websites = $this->server->websites()->get();
        $runner = $runner->server($this->server)->create();

        $logFile = match($this->log) {
            'caddy' => '/var/log/caddy',
            'mysql' => '/var/log/mysql/error.log',
            'php' => '/var/log/php',
            default => '/var/log/apt/history.log'
        };

        $logs = array_filter(explode(PHP_EOL, $runner?->execute([
            "tail -200 $logFile",
        ])->getOutput())) ?? [];

        return view('livewire.scenes.servers.show', [
            'websites' => $websites,
            'logs' => $logs,
        ])->layout('components.layouts.app');
    }
}
