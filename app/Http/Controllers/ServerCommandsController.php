<?php

namespace App\Http\Controllers;

use App\Actions\Server\CancelServerCommandAction;
use App\Actions\Server\QueueServerCommandAction;
use App\Http\Responses\PlainTextLogDownload;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServerCommandsController extends Controller
{
    public function index(Request $request, Server $server): View
    {
        $this->authorize('view', $server);
        $filters = $this->filters($request);

        return view('scenes.servers.commands', [
            'server' => $server,
            'executions' => $this->filteredExecutions($server, $filters)
                ->with('rerunFrom:id')
                ->latest('id')
                ->paginate(25)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'metrics' => $this->metrics($server, $filters),
            'statuses' => ServerCommandExecution::STATUSES,
        ]);
    }

    /**
     * @param  array{status: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, active: int, succeeded: int, failed: int, canceled: int, output: int}
     */
    private function metrics(Server $server, array $filters): array
    {
        return [
            'total' => $this->filteredExecutions($server, $filters)->count(),
            'active' => $this->filteredExecutions($server, $filters)->active()->count(),
            'succeeded' => $this->filteredExecutions($server, $filters)
                ->where('status', ServerCommandExecution::STATUS_SUCCEEDED)
                ->count(),
            'failed' => $this->filteredExecutions($server, $filters)
                ->where('status', ServerCommandExecution::STATUS_FAILED)
                ->count(),
            'canceled' => $this->filteredExecutions($server, $filters)
                ->where('status', ServerCommandExecution::STATUS_CANCELED)
                ->count(),
            'output' => $this->filteredExecutions($server, $filters)->whereNotNull('output')->count(),
        ];
    }

    public function export(Request $request, Server $server): StreamedResponse
    {
        $this->authorize('view', $server);
        $filters = $this->filters($request);
        $filename = "lessbuild-server-{$server->id}-commands-".now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($server, $filters): void {
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

            $this->filteredExecutions($server, $filters)
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

    public function destroy(Server $server, int $execution): RedirectResponse
    {
        $this->authorize('view', $server);
        $execution = $server->commandExecutions()->findOrFail($execution);
        $deleted = $server->commandExecutions()
            ->whereKey($execution->id)
            ->whereIn('status', ServerCommandExecution::TERMINAL_STATUSES)
            ->delete();

        return $deleted === 1
            ? back()->with('success', __('Command history record deleted.'))
            : back()->with('info', __('Queued or running commands cannot be deleted.'));
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

    /** @return array{status: ?string, date_from: ?string, date_to: ?string} */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();

        return [
            'status' => in_array($status, ServerCommandExecution::STATUSES, true) ? $status : null,
            'date_from' => $this->date($request->string('date_from')->toString()),
            'date_to' => $this->date($request->string('date_to')->toString()),
        ];
    }

    /** @param array{status: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredExecutions(Server $server, array $filters): HasMany
    {
        return $server->commandExecutions()
            ->when($filters['status'], fn ($query, string $status) => $query
                ->where('status', $status))
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('created_at', '<=', $date));
    }

    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
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
