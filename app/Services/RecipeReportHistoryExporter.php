<?php

namespace App\Services;

use App\Models\RecipeReport;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeReportHistoryExporter
{
    public function __construct(private readonly RecipeReportQuery $reports) {}

    /**
     * Stream the authenticated reporter's filtered report history as a private CSV download.
     *
     * @param  array{search: ?string, status: string, availability: string, updates: string, reason: ?string, sort: string}  $filters
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-my-community-reports-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Report ID',
                'Recipe ID',
                'Recipe',
                'Category',
                'Recipe availability',
                'Issue type',
                'Report status',
                'Details',
                'Resolution note',
                'Reported at',
                'Resolved at',
                'Updated at',
            ], ',', '"', '');

            $this->reports->orderedReporter(
                $this->reports->forReporter($user, $filters)
                    ->select([
                        'id',
                        'user_id',
                        'recipe_id',
                        'reason',
                        'details',
                        'resolved_at',
                        'resolution_note',
                        'created_at',
                        'updated_at',
                    ])
                    ->with('recipe:id,name,category,is_published,published_at'),
                $filters,
            )
                ->lazy(250)
                ->each(function (RecipeReport $report) use ($output): void {
                    fputcsv($output, [
                        $report->id,
                        $report->recipe_id,
                        $this->csvCell($report->recipe->name),
                        $this->csvCell($report->recipe->category),
                        $report->recipe->is_published && $report->recipe->published_at !== null ? 'published' : 'unpublished',
                        $this->csvCell($report->reason),
                        $report->resolved_at === null ? 'needs_review' : 'resolved',
                        $this->csvCell($report->details),
                        $this->csvCell($report->resolution_note),
                        $report->created_at?->toIso8601String(),
                        $report->resolved_at?->toIso8601String(),
                        $report->updated_at?->toIso8601String(),
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
