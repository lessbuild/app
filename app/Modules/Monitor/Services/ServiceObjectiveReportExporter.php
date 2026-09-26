<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\ServiceLevelObjective;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

final class ServiceObjectiveReportExporter
{
    /**
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
            $this->text($objective->name),
            $this->text($objective->indicatorLabel()),
            $this->text($objective->environment->application->name.' / '.$objective->environment->name),
            $this->text($objective->scopeLabel()),
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
            $this->text((string) $report['status']),
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

    public function filename(ServiceLevelObjective $objective, CarbonImmutable $until): string
    {
        return Str::slug($objective->name).'-slo-report-'.$until->format('Ymd-His').'.csv';
    }

    private function text(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
