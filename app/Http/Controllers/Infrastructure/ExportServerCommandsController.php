<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Support\SpreadsheetCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The server's command history as CSV (without output). Cells that a spreadsheet would treat as formulas are escaped. */
final class ExportServerCommandsController
{
    /**
     * Stream the command history as a UTF-8 CSV, newest first.
     *
     * @param  Project  $project
     * @param  Server  $server
     * @return StreamedResponse
     */
    public function __invoke(Project $project, Server $server): StreamedResponse
    {

        return response()->streamDownload(function () use ($server): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'command', 'status', 'rerun_of', 'exit_code', 'queued_at', 'started_at', 'finished_at', 'duration_seconds', 'run_by'], ',', '"', '');
            $server->commandExecutions()->with('user')->orderByDesc('id')->lazy(250)->each(function (ServerCommandExecution $execution) use ($out): void {
                fputcsv($out, [
                    $execution->id, SpreadsheetCell::text($execution->command), $execution->status, $execution->rerun_from_execution_id, $execution->exit_code,
                    $execution->created_at?->toIso8601String(), $execution->started_at?->toIso8601String(), $execution->finished_at?->toIso8601String(),
                    $execution->durationSeconds(), SpreadsheetCell::text($execution->user->email ?? ''),
                ], ',', '"', '');
            });
            fclose($out);
        }, "server-{$server->id}-commands-".now('UTC')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
