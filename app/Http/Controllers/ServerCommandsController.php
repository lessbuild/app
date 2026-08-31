<?php

namespace App\Http\Controllers;

use App\Actions\Server\CancelServerCommandAction;
use App\Actions\Server\QueueServerCommandAction;
use App\Http\Responses\PlainTextLogDownload;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ServerCommandsController extends Controller
{
    public function index(Request $request, Server $server): View
    {
        $this->authorize('view', $server);
        $status = $request->string('status')->toString();
        $status = in_array($status, ServerCommandExecution::STATUSES, true) ? $status : null;

        return view('scenes.servers.commands', [
            'server' => $server,
            'executions' => $server->commandExecutions()
                ->with('rerunFrom:id')
                ->when($status, fn ($query, string $value) => $query->where('status', $value))
                ->latest('id')
                ->paginate(25)
                ->appends(array_filter(['status' => $status])),
            'status' => $status,
            'statuses' => ServerCommandExecution::STATUSES,
        ]);
    }

    public function cancel(
        Request $request,
        Server $server,
        int $execution,
        CancelServerCommandAction $cancel,
    ): RedirectResponse {
        $this->authorize('view', $server);
        $execution = $server->commandExecutions()->findOrFail($execution);

        return $cancel->handle($execution, $request->user())
            ? back()->with('success', __('Queued command canceled.'))
            : back()->with('info', __('This command is no longer queued and cannot be canceled.'));
    }

    public function rerun(
        Request $request,
        Server $server,
        int $execution,
        QueueServerCommandAction $queue,
    ): RedirectResponse {
        $this->authorize('view', $server);
        $source = $server->commandExecutions()->findOrFail($execution);
        $rerun = $queue->handle($server, $request->user(), $source->command, $source->id);

        return back()->with('success', __('Command #:id was queued from history.', ['id' => $rerun->id]));
    }

    public function downloadOutput(
        Server $server,
        int $execution,
        PlainTextLogDownload $download,
    ): Response {
        $this->authorize('view', $server);
        $execution = $server->commandExecutions()
            ->whereNotNull('output')
            ->findOrFail($execution);

        return $download->make(
            $execution->output,
            "lessbuild-server-{$server->id}-command-{$execution->id}.log",
        );
    }
}
