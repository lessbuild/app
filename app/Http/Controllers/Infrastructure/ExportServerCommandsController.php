<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The server's command history as CSV (without output). Cells that a spreadsheet would treat as formulas are escaped. */
final class ExportServerCommandsController
{
    /**
     * Streams the command history as a UTF-8 CSV, newest first.
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
                    $execution->id, $this->cell($execution->command), $execution->status, $execution->rerun_from_execution_id, $execution->exit_code,
                    $execution->created_at?->toIso8601String(), $execution->started_at?->toIso8601String(), $execution->finished_at?->toIso8601String(),
                    $execution->durationSeconds(), $this->cell($execution->user->email ?? ''),
                ], ',', '"', '');
            });
            fclose($out);
        }, "server-{$server->id}-commands-".now('UTC')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * Prefixes a value with `'` when a spreadsheet would read it as a formula, so a command like `=HYPERLINK(…)` stays
     * text.
     */
    private function cell(string $value): string
    {
        return preg_match('/\A[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
