<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServerInventoryExporter
{
    public function __construct(private readonly ServerInventoryQuery $servers) {}

    /**
     * Stream filtered workspace server inventory with provider details and website counts as private CSV.
     *
     * @param  array{search: ?string, status: ?string, provisioning: ?string}  $filters
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-servers-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Server ID',
                'Display name',
                'Cloud hostname',
                'Cloud identifier',
                'Type',
                'Region',
                'Size',
                'Image',
                'Public IP',
                'Private IP',
                'Provider',
                'Provider type',
                'Status',
                'Website count',
                'Provisioned at',
                'Created at',
            ], ',', '"', '');

            $this->servers->for($user, $filters)
                ->with('provider')
                ->withCount('websites')
                ->latest('servers.id')
                ->lazy(250)
                ->each(function (Server $server) use ($output): void {
                    fputcsv($output, [
                        $server->id,
                        $this->csvCell($server->label),
                        $this->csvCell($server->name),
                        $this->csvCell($server->identifier),
                        $this->csvCell($server->type?->value),
                        $this->csvCell($server->region),
                        $this->csvCell($server->size),
                        $this->csvCell($server->image),
                        $this->csvCell($server->public_ip),
                        $this->csvCell($server->private_ip),
                        $this->csvCell($server->provider?->name),
                        $this->csvCell($server->provider?->provider),
                        $this->csvCell($server->provisioning_status),
                        $server->websites_count,
                        $server->provisioned_at?->toIso8601String(),
                        $server->created_at?->toIso8601String(),
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
