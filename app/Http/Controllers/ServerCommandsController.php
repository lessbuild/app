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
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServerCommandsController extends Controller
{
    public function index(Request $request, Server $server): View
    {
        $this->authorize('view', $server);
        $status = $this->status($request);

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

    public function export(Request $request, Server $server): StreamedResponse
    {
        $this->authorize('view', $server);
        $status = $this->status($request);
        $filename = "lessbuild-server-{$server->id}-commands-".now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($server, $status): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Execution ID',
                'Command',
                'Status',
                'Rerun from execution ID',
                'Exit code',
                'Queued at',
                'Started at',
                'Finished at',
                'Output available',
            ], ',', '"', '');

            $server->commandExecutions()
                ->when($status, fn ($query, string $value) => $query->where('status', $value))
                ->latest('id')
                ->lazy(250)
                ->each(function (ServerCommandExecution $execution) use ($output): void {
                    fputcsv($output, [
                        $execution->id,
                        $this->csvCell($execution->command),
                        $this->csvCell($execution->status),
                        $execution->rerun_from_execution_id,
                        $execution->exit_code,
                        $execution->created_at?->toIso8601String(),
                        $execution->started_at?->toIso8601String(),
                        $execution->finished_at?->toIso8601String(),
                        $execution->output === null ? 'no' : 'yes',
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
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

    private function status(Request $request): ?string
    {
        $status = $request->string('status')->toString();

        return in_array($status, ServerCommandExecution::STATUSES, true) ? $status : null;
    }

    private function csvCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
