<?php

namespace App\Services;

use App\Models\User;
use App\Models\Website;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsiteInventoryExporter
{
    public function __construct(private readonly WebsiteInventoryQuery $websites) {}

    /**
     * Stream filtered workspace website inventory, monitoring settings, and placement metadata as private CSV.
     *
     * @param  array{search: ?string, status: ?string, health: ?string, attention: ?string, provisioning: ?string}  $filters
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-websites-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Website ID',
                'Name',
                'Domain',
                'Description',
                'Server',
                'Provisioning status',
                'Health check',
                'Automatic monitoring',
                'Automatic check interval minutes',
                'Outage confirmation failures',
                'Health status',
                'Health failure count',
                'Last health check at',
                'Release retention',
                'Repository count',
                'Provisioned at',
                'Created at',
            ], ',', '"', '');

            $this->websites->for($user, $filters)
                ->with('server')
                ->withCount('repositories')
                ->latest('websites.id')
                ->lazy(250)
                ->each(function (Website $website) use ($output): void {
                    fputcsv($output, [
                        $website->id,
                        $this->csvCell($website->name),
                        $this->csvCell($website->url),
                        $this->csvCell($website->description),
                        $this->csvCell($website->server?->label),
                        $this->csvCell($website->provisioning_status),
                        $website->health_check_enabled ? 'enabled' : 'disabled',
                        $website->health_check_enabled
                            ? ($website->health_monitoring_enabled ? 'enabled' : 'paused')
                            : 'disabled',
                        $website->health_check_interval_minutes,
                        $website->health_failure_threshold,
                        $this->csvCell($website->health_check_enabled ? $website->health_status : 'disabled'),
                        $website->health_failure_count,
                        $website->health_last_checked_at?->toIso8601String(),
                        $website->release_retention,
                        $website->repositories_count,
                        $website->provisioned_at?->toIso8601String(),
                        $website->created_at?->toIso8601String(),
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
     * Convert integer cells to text, preserve null, and escape values that could be interpreted as spreadsheet formulas.
     */
    private function csvCell(string|int|null $value): ?string
    {
        return CsvCell::escape($value);
    }
}
