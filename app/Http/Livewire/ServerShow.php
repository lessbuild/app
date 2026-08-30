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
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Services\Runner  $runner
     * @return \Illuminate\Contracts\View\View
     *
     * @throws \Exception
     */
    public function render(Request $request, Runner $runner): View
    {
        $this->server->refresh();
        abort_unless((int) auth()->id() === (int) $this->server->user_id, 403);
        $websites = $this->server->websites()->get();
        $logs = [];
        $logError = null;

        if ($this->server->provisioning_status === Server::STATUS_ACTIVE) {
            $logFile = match ($this->log) {
                'caddy' => '/var/log/caddy',
                'mysql' => '/var/log/mysql/error.log',
                'php' => '/var/log/php',
                default => '/var/log/apt/history.log',
            };

            try {
                $process = $runner->server($this->server)->create()->execute([
                    "tail -200 $logFile",
                ]);

                if ($process->isSuccessful()) {
                    $logs = array_filter(explode(PHP_EOL, $process->getOutput()));
                } else {
                    $logError = trim($process->getErrorOutput()) ?: __('Unable to retrieve logs.');
                }
            } catch (\Throwable $exception) {
                report($exception);
                $logError = __('Unable to connect to this server yet.');
            }
        }

        return view('livewire.scenes.servers.show', [
            'websites' => $websites,
            'logs' => $logs,
            'logError' => $logError,
        ])->layout('components.layouts.app');
    }
}
