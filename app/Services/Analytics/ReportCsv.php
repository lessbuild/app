<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsSite;
use App\Queries\Analytics\AnalyticsReportQuery;
use RuntimeException;

/** A site's report as a CSV: each headline number, page speed measure and breakdown row, one per line. */
final class ReportCsv
{
    /**
     * Create a new ReportCsv instance.
     *
     * @param  AnalyticsReportQuery  $reports  Builds the report.
     */
    public function __construct(private readonly AnalyticsReportQuery $reports) {}

    /**
     * Build the report for the period with these filters and write it as CSV (section, label, value).
     *
     * @param  AnalyticsSite  $site
     * @param  int|ReportPeriod  $period  days back, or a period
     * @param  array<string, mixed>  $filters
     * @return string
     */
    public function render(AnalyticsSite $site, int|ReportPeriod $period, array $filters): string
    {
        $summary = $this->reports->handle($site, $period, $filters);
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the export.');
        }
        fputcsv($handle, ['section', 'label', 'value']);
        foreach ($summary['metrics'] as $metric) {
            fputcsv($handle, ['metrics', $metric['label'], $metric['value']]);
        }
        foreach ($summary['vitals']['metrics'] ?? [] as $vital => $measure) {
            if ($measure['value'] !== null) {
                fputcsv($handle, ['page_speed_p75', $vital, $measure['value']]);
            }
        }
        foreach ([
            'pages' => $summary['pages'],
            'entry_pages' => $summary['entryPages'],
            'exit_pages' => $summary['exitPages'],
            'sources' => $summary['sources'],
            'countries' => $summary['countries'],
            'devices' => $summary['devices'],
            'browsers' => $summary['browsers'],
            'operating_systems' => $summary['operatingSystems'],
            'campaigns' => $summary['campaigns'],
            'outbound_links' => $summary['outboundLinks'],
            'file_downloads' => $summary['fileDownloads'],
            'pages_not_found' => $summary['notFound'],
        ] as $section => $items) {
            foreach ($items as $item) {
                fputcsv($handle, [$section, $item['label'], $item['value']]);
            }
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
