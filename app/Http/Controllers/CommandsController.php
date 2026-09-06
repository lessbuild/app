<?php

namespace App\Http\Controllers;

use App\Models\ServerCommandExecution;
use App\Support\DateRange;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommandsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $this->filters($request);

        return view('commands.index', [
            'executions' => $this->filteredExecutions($request, $filters)
                ->select(['id', 'server_id', 'user_id', 'status', 'exit_code', 'created_at', 'started_at', 'finished_at'])
                ->selectRaw('CASE WHEN output IS NULL THEN 0 ELSE 1 END AS output_available')
                ->with('server:id,name,display_name')
                ->latest('id')
                ->paginate(25)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'metrics' => $this->metrics($request, $filters),
            'servers' => $request->user()->workspaceServers()->orderBy('name')->get(['id', 'name', 'display_name']),
            'statuses' => ServerCommandExecution::STATUSES,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'lessbuild-command-center-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Execution ID',
                'Server',
                'Status',
                'Exit code',
                'Queued at',
                'Started at',
                'Finished at',
                'Duration seconds',
                'Output available',
            ], ',', '"', '');

            $this->filteredExecutions($request, $filters)
                ->select(['id', 'server_id', 'status', 'exit_code', 'created_at', 'started_at', 'finished_at'])
                ->selectRaw('CASE WHEN output IS NULL THEN 0 ELSE 1 END AS output_available')
                ->with('server:id,name,display_name')
                ->latest('id')
                ->lazy(250)
                ->each(function (ServerCommandExecution $execution) use ($output): void {
                    fputcsv($output, [
                        $execution->id,
                        $this->csvCell($execution->server->label),
                        $this->csvCell($execution->status),
                        $execution->exit_code,
                        $execution->created_at?->toIso8601String(),
                        $execution->started_at?->toIso8601String(),
                        $execution->finished_at?->toIso8601String(),
                        $execution->durationSeconds(),
                        $execution->output_available ? 'yes' : 'no',
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array{server_id: ?int, status: ?string, output: ?string, active: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, active: int, succeeded: int, failed: int, canceled: int, latest_at: CarbonInterface|null}
     */
    private function metrics(Request $request, array $filters): array
    {
        $latest = $this->filteredExecutions($request, $filters)
            ->select(['id', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->first();

        return [
            'total' => $this->filteredExecutions($request, $filters)->count(),
            'active' => $this->filteredExecutions($request, $filters)->active()->count(),
            'succeeded' => $this->filteredExecutions($request, $filters)
                ->where('status', ServerCommandExecution::STATUS_SUCCEEDED)
                ->count(),
            'failed' => $this->filteredExecutions($request, $filters)
                ->where('status', ServerCommandExecution::STATUS_FAILED)
                ->count(),
            'canceled' => $this->filteredExecutions($request, $filters)
                ->where('status', ServerCommandExecution::STATUS_CANCELED)
                ->count(),
            'latest_at' => $latest?->created_at,
        ];
    }

    /** @return array{server_id: ?int, status: ?string, output: ?string, active: ?string, date_from: ?string, date_to: ?string} */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();
        $output = $request->string('output')->toString();
        $serverId = filter_var($request->query('server_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        [$dateFrom, $dateTo] = DateRange::normalize(
            $request->string('date_from')->toString(),
            $request->string('date_to')->toString(),
        );

        return [
            'server_id' => $serverId ?: null,
            'status' => in_array($status, ServerCommandExecution::STATUSES, true) ? $status : null,
            'output' => in_array($output, ['available', 'missing'], true) ? $output : null,
            'active' => $request->boolean('active') ? '1' : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /** @param array{server_id: ?int, status: ?string, output: ?string, active: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredExecutions(Request $request, array $filters): Builder
    {
        return ServerCommandExecution::query()
            ->whereHas('server', fn ($query) => $query->where('organization_id', $request->user()->current_organization_id))
            ->when($filters['server_id'], fn ($query, int $serverId) => $query->where('server_id', $serverId))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['output'] === 'available', fn ($query) => $query->whereNotNull('output'))
            ->when($filters['output'] === 'missing', fn ($query) => $query->whereNull('output'))
            ->when($filters['active'], fn ($query) => $query->active())
            ->when($filters['date_from'], fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query->whereDate('created_at', '<=', $date));
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
