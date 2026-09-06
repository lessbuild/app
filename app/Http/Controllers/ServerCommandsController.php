<?php

namespace App\Http\Controllers;

use App\Actions\Server\CancelServerCommandAction;
use App\Actions\Server\QueueServerCommandAction;
use App\Http\Responses\PlainTextLogDownload;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Support\CsvCell;
use App\Support\DateRange;
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
     * @param  array{execution: ?int, status: ?string, output: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, active: int, succeeded: int, failed: int, canceled: int, output: int}
     */
    private function metrics(Server $server, array $filters): array
    {
        $counts = $this->filteredExecutions($server, $filters)
            ->selectRaw('COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) AS active,
                COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS succeeded,
                COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS failed,
                COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS canceled,
                COALESCE(SUM(CASE WHEN output IS NOT NULL THEN 1 ELSE 0 END), 0) AS output', [
                ServerCommandExecution::STATUS_QUEUED,
                ServerCommandExecution::STATUS_RUNNING,
                ServerCommandExecution::STATUS_SUCCEEDED,
                ServerCommandExecution::STATUS_FAILED,
                ServerCommandExecution::STATUS_CANCELED,
            ])
            ->toBase()
            ->first();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'succeeded' => (int) $counts->succeeded,
            'failed' => (int) $counts->failed,
            'canceled' => (int) $counts->canceled,
            'output' => (int) $counts->output,
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
                'Duration seconds',
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
                        $execution->durationSeconds(),
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
        ServerCommandExecution $execution,
        CancelServerCommandAction $cancel,
    ): RedirectResponse {
        $this->authorize('update', $server);
        $this->ensureBelongsToServer($execution, $server);

        return $cancel->handle($execution, $request->user())
            ? back()->with('success', __('Queued command canceled.'))
            : back()->with('info', __('This command is no longer queued and cannot be canceled.'));
    }

    public function rerun(
        Request $request,
        Server $server,
        ServerCommandExecution $execution,
        QueueServerCommandAction $queue,
    ): RedirectResponse {
        $this->authorize('update', $server);
        $this->ensureBelongsToServer($execution, $server);
        $rerun = $queue->handle($server, $request->user(), $execution->command, $execution->id);

        return back()->with('success', __('Command #:id was queued from history.', ['id' => $rerun->id]));
    }

    public function destroy(Server $server, ServerCommandExecution $execution): RedirectResponse
    {
        $this->authorize('delete', $server);
        $this->ensureBelongsToServer($execution, $server);
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
        ServerCommandExecution $execution,
        PlainTextLogDownload $download,
    ): Response {
        $this->authorize('view', $server);
        $this->ensureBelongsToServer($execution, $server);
        abort_if($execution->output === null, 404);

        return $download->make(
            $execution->output,
            "lessbuild-server-{$server->id}-command-{$execution->id}.log",
        );
    }

    /**
     * @param  ServerCommandExecution  $execution  The implicitly bound history record.
     * @param  Server  $server  The authorized parent from the same route.
     * @return void Reject a mismatched nested record with the same 404 as a scoped query.
     */
    private function ensureBelongsToServer(ServerCommandExecution $execution, Server $server): void
    {
        abort_unless((int) $execution->server_id === (int) $server->id, 404);
    }

    /** @return array{execution: ?int, status: ?string, output: ?string, date_from: ?string, date_to: ?string} */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();
        $output = $request->string('output')->toString();
        $execution = filter_var($request->query('execution'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        [$dateFrom, $dateTo] = DateRange::normalize(
            $request->string('date_from')->toString(),
            $request->string('date_to')->toString(),
        );

        return [
            'execution' => $execution ?: null,
            'status' => in_array($status, ServerCommandExecution::STATUSES, true) ? $status : null,
            'output' => in_array($output, ['available', 'missing'], true) ? $output : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /** @param array{execution: ?int, status: ?string, output: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredExecutions(Server $server, array $filters): HasMany
    {
        return $server->commandExecutions()
            ->when($filters['execution'], fn ($query, int $execution) => $query->whereKey($execution))
            ->when($filters['status'], fn ($query, string $status) => $query
                ->where('status', $status))
            ->when($filters['output'] === 'available', fn ($query) => $query->whereNotNull('output'))
            ->when($filters['output'] === 'missing', fn ($query) => $query->whereNull('output'))
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
        return CsvCell::escape($value);
    }
}
