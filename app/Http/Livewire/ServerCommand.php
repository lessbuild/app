<?php

namespace App\Http\Livewire;

use App\Actions\Server\CancelServerCommandAction;
use App\Actions\Server\QueueServerCommandAction;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ServerCommand extends Component
{
    public bool $open = false;

    public string $command = '';

    protected $listeners = ['open-server-command' => 'open'];

    public Server $model;

    public function mount(Server $model): void
    {
        Gate::authorize('view', $model);
        $this->model = $model;
    }

    public function open(): void
    {
        Gate::authorize('update', $this->model);
        $this->model->refresh();
        abort_unless($this->model->provisioning_status === Server::STATUS_ACTIVE, 409);
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->reset('command');
        $this->resetValidation();
    }

    public function run(QueueServerCommandAction $queue): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::authorize('update', $this->model);
        $this->validate(['command' => ['required', 'string', 'max:1000']]);
        if (str_contains($this->command, "\0")) {
            $this->addError('command', __('Commands cannot contain null bytes.'));

            return;
        }

        $queue->handle($this->model, $user, $this->command);
        $this->reset('command');
    }

    public function cancel(int $executionId, CancelServerCommandAction $cancel): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::authorize('update', $this->model);
        $execution = $this->model->commandExecutions()->findOrFail($executionId);

        if (! $cancel->handle($execution, $user)) {
            $this->addError('cancel', __('This command is no longer queued and cannot be canceled.'));
        }
    }

    public function rerun(int $executionId, QueueServerCommandAction $queue): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::authorize('update', $this->model);
        $source = $this->model->commandExecutions()->findOrFail($executionId);

        $queue->handle($this->model, $user, $source->command, $source->id);
    }

    /**
     * @throws \Exception
     */
    public function render(): View
    {
        Gate::authorize('view', $this->model);

        $executions = $this->model->commandExecutions()
            ->with('rerunFrom:id')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('livewire.scenes.servers.command', [
            'executions' => $executions,
            'shouldPoll' => $this->open && $executions->contains(
                fn (ServerCommandExecution $execution): bool => in_array(
                    $execution->status,
                    ServerCommandExecution::ACTIVE_STATUSES,
                    true,
                ),
            ),
        ]);
    }
}
