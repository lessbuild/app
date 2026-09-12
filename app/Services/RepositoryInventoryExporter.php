<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepositoryInventoryExporter
{
    public function __construct(private readonly RepositoryInventoryQuery $repositories) {}

    /**
     * Stream filtered workspace repositories with provider, placement, latest-deployment, and webhook metadata as private CSV.
     *
     * @param  array{search: ?string, provider_id: ?int, website_id: ?int, status: ?string}  $filters
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-repositories-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Repository ID',
                'Name',
                'URL',
                'Branch',
                'Description',
                'Provider',
                'Provider type',
                'Website',
                'Website domain',
                'Server',
                'Latest deployment status',
                'Latest revision',
                'Latest deployment at',
                'Webhook enabled',
                'Created at',
            ], ',', '"', '');

            $this->repositories->for($user, $filters)
                ->with(['provider', 'website.server', 'latestBuild'])
                ->latest('repositories.id')
                ->lazy(250)
                ->each(function (Repository $repository) use ($output): void {
                    fputcsv($output, [
                        $repository->id,
                        $this->csvCell($repository->name),
                        $this->csvCell($repository->url),
                        $this->csvCell($repository->branch),
                        $this->csvCell($repository->description),
                        $this->csvCell($repository->provider?->name),
                        $this->csvCell($repository->provider?->provider),
                        $this->csvCell($repository->website?->name),
                        $this->csvCell($repository->website?->url),
                        $this->csvCell($repository->website?->server?->label),
                        $this->csvCell($repository->latestBuild?->status),
                        $this->csvCell($repository->latestBuild?->revision),
                        $repository->latestBuild?->created_at?->toIso8601String(),
                        $repository->webhook_enabled ? 'yes' : 'no',
                        $repository->created_at?->toIso8601String(),
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
