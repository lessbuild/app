<?php

namespace App\Http\Livewire;

use App\Models\Server;
use App\Services\Runner;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ServerCommand extends Component
{
    public bool $open = false;

    public string $command = '';

    public string $output = '';

    protected $listeners = ['open-server-command' => 'open'];

    /**
     * @var \App\Models\Server
     */
    public Server $model;

    public function mount(Server $model): void
    {
        abort_unless((int) auth()->id() === (int) $model->user_id, 403);
        $this->model = $model;
    }

    public function open(): void
    {
        $this->model->refresh();
        abort_unless($this->model->provisioning_status === Server::STATUS_ACTIVE, 409);
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->reset(['command', 'output']);
        $this->resetValidation();
    }

    public function run(Runner $runner): void
    {
        $this->validate(['command' => ['required', 'string', 'max:1000']]);
        $this->model->refresh();

        if ($this->model->provisioning_status !== Server::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'command' => __('Commands are available after provisioning finishes.'),
            ]);
        }

        try {
            $process = $runner->server($this->model)->create()->execute($this->command);
            $this->output = trim($process->getOutput().PHP_EOL.$process->getErrorOutput());

            if (! $process->isSuccessful() && $this->output === '') {
                $this->output = __('Command failed with exit code :code.', ['code' => $process->getExitCode()]);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->output = __('Unable to execute command: :message', ['message' => $exception->getMessage()]);
        }
    }

    /**
     * @return \Illuminate\Contracts\View\View
     *
     * @throws \Exception
     */
    public function render(): View
    {
        abort_unless((int) auth()->id() === (int) $this->model->user_id, 403);

        return view('livewire.scenes.servers.command');
    }
}
