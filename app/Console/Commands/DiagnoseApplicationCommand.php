<?php

namespace App\Console\Commands;

use App\Services\OperationalDiagnostics;
use Illuminate\Console\Command;

class DiagnoseApplicationCommand extends Command
{
    protected $signature = 'lessbuild:diagnose {--json : Emit machine-readable JSON}';

    protected $description = 'Run read-only application configuration and readiness diagnostics';

    /**
     * Run operational readiness checks and present either a diagnostic table or JSON report.
     *
     * @param  OperationalDiagnostics  $diagnostics  Collector of operational readiness checks and diagnostic evidence.
     * @return int SUCCESS when the application is ready, otherwise FAILURE.
     */
    public function handle(OperationalDiagnostics $diagnostics): int
    {
        $checks = $diagnostics->run();
        $passed = collect($checks)->every(fn (array $check): bool => $check['passed']);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $passed ? 'ready' : 'failed',
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Check', 'Status', 'Detail'],
                array_map(fn (array $check): array => [
                    $check['name'],
                    $check['passed'] ? 'PASS' : 'FAIL',
                    $check['detail'],
                ], $checks),
            );
            $passed
                ? $this->info('Lessbuild diagnostics passed.')
                : $this->error('Lessbuild diagnostics found one or more failures.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
