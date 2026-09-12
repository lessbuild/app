<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderInventoryExporter
{
    public function __construct(private readonly ProviderInventoryQuery $providers) {}

    /**
     * Stream the filtered workspace provider inventory as a private CSV download.
     *
     * @param  array{search: ?string, type: ?string, usage: ?string, connection: ?string}  $filters
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-providers-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Provider ID',
                'Name',
                'Type',
                'Description',
                'Servers',
                'Server count',
                'Repositories',
                'Repository count',
                'Connection status',
                'Automatic monitoring',
                'Automatic interval minutes',
                'Failure threshold',
                'Consecutive failures',
                'Connection checked at',
                'Created at',
                'Updated at',
            ], ',', '"', '');

            $this->providers->for($user, $filters)
                ->with([
                    'servers' => fn ($query) => $query
                        ->select(['id', 'provider_id', 'name', 'display_name'])
                        ->orderBy('name'),
                    'repositories' => fn ($query) => $query
                        ->select(['id', 'provider_id', 'name'])
                        ->orderBy('name'),
                ])
                ->withCount(['servers', 'repositories'])
                ->latest('providers.id')
                ->lazy(250)
                ->each(function (Provider $provider) use ($output): void {
                    fputcsv($output, [
                        $provider->id,
                        $this->csvCell($provider->name),
                        $this->csvCell($provider->provider),
                        $this->csvCell($provider->description),
                        $this->csvCell($provider->servers->map->label->implode('; ')),
                        $provider->servers_count,
                        $this->csvCell($provider->repositories->pluck('name')->implode('; ')),
                        $provider->repositories_count,
                        $provider->connectionHealth(),
                        $provider->connection_monitoring_enabled ? 'enabled' : 'paused',
                        $provider->connection_check_interval_minutes,
                        $provider->connection_failure_threshold,
                        $provider->connection_failure_count,
                        $provider->connection_checked_at?->toIso8601String(),
                        $provider->created_at?->toIso8601String(),
                        $provider->updated_at?->toIso8601String(),
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
     * Preserve null values and escape text that could be interpreted as a spreadsheet formula.
     */
    private function csvCell(?string $value): ?string
    {
        return CsvCell::escape($value);
    }
}
