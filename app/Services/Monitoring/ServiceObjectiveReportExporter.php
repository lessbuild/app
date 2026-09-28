<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\ServiceLevelObjective;
use App\Support\SpreadsheetCell;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

final class ServiceObjectiveReportExporter
{
    /**
     * The SLO report as a two-line CSV, with text cells protected from spreadsheet formulas.
     *
     * @param  array<string, mixed>  $report
     */
    public function csv(ServiceLevelObjective $objective, array $report): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('The SLO report could not be created.');
        }

        fputcsv($handle, [
            'objective', 'indicator', 'environment', 'scope', 'from_utc', 'until_utc', 'target_percent',
            'compliance_percent', 'total_requests', 'observed_requests', 'good_requests', 'bad_requests',
            'unknown_requests', 'budget_remaining_percent', 'burn_rate', 'status', 'generated_at_utc',
        ], ',', '"', '\\');
        fputcsv($handle, [
            SpreadsheetCell::text($objective->name),
            SpreadsheetCell::text($objective->indicatorLabel()),
            SpreadsheetCell::text($objective->environment->project->name.' / '.$objective->environment->name),
            SpreadsheetCell::text($objective->scopeLabel()),
            $report['from']->toISOString(),
            $report['until']->toISOString(),
            $report['target'],
            $report['compliance'],
            $report['total'],
            $report['observed'],
            $report['good'],
            $report['bad'],
            $report['unknown'],
            $report['budget_remaining'],
            $report['burn_rate'],
            SpreadsheetCell::text((string) $report['status']),
            now('UTC')->toISOString(),
        ], ',', '"', '\\');
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new RuntimeException('The SLO report could not be read.');
        }

        return $csv;
    }

    /**
     * A download name from the SLO's name and the report's end.
     */
    public function filename(ServiceLevelObjective $objective, CarbonImmutable $until): string
    {
        return Str::slug($objective->name).'-slo-report-'.$until->format('Ymd-His').'.csv';
    }
}
