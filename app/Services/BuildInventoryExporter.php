<?php

namespace App\Services;

use App\Models\Build;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuildInventoryExporter
{
    public function __construct(private readonly BuildInventoryQuery $builds) {}

    /**
     * Stream filtered workspace deployment history, release provenance, and operator notes as private CSV.
     *
     * @param  array{repository_id: ?int, website_id: ?int, server_id: ?int, provider_id: ?int, status: ?string, trigger: ?string, search: ?string, active: ?string, latest: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-builds-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Build ID',
                'Repository',
                'Website',
                'Server',
                'Status',
                'Trigger',
                'Revision',
                'Commit message',
                'Operator note',
                'Promoted from build',
                'Promotion note',
                'Created at',
                'Started at',
                'Finished at',
                'Duration seconds',
            ], ',', '"', '');

            $this->builds->for($user, $filters)
                ->latest('builds.id')
                ->lazy(250)
                ->each(function (Build $build) use ($output): void {
                    $repository = $build->repository;
                    $website = $repository->website;
                    fputcsv($output, [
                        $build->id,
                        $this->csvCell($repository->name),
                        $this->csvCell($website?->name),
                        $this->csvCell($website?->server?->label),
                        $build->status,
                        $build->trigger_source,
                        $build->revision,
                        $this->csvCell($build->commit_message),
                        $this->csvCell($build->operator_note),
                        $build->promoted_from_build_id,
                        $this->csvCell($build->promotion_note),
                        $build->created_at?->toIso8601String(),
                        $build->started_at?->toIso8601String(),
                        $build->finished_at?->toIso8601String(),
                        $build->durationSeconds(),
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
