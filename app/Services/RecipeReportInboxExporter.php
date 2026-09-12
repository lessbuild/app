<?php

namespace App\Services;

use App\Models\RecipeReport;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeReportInboxExporter
{
    public function __construct(private readonly RecipeReportQuery $reports) {}

    /**
     * Stream filtered community reports about the user's contributed recipes as a private CSV download.
     *
     * @param  array{search: ?string, status: string, reason: ?string, date_from: ?string, date_to: ?string, age: ?string, sort: string, recipe: ?int, report: ?int}  $filters
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-community-feedback-'.now()->utc()->format('Ymd-His').'.csv';

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
                'Issue type',
                'Review status',
                'Details',
                'Reported at',
                'Resolved at',
                'Resolution note',
            ], ',', '"', '');

            $this->reports->ordered(
                $this->reports->forContributor($user, $filters)
                    ->select(['id', 'recipe_id', 'reason', 'details', 'resolved_at', 'resolution_note', 'created_at', 'updated_at'])
                    ->with('recipe:id,name,category'),
                $filters,
            )
                ->lazy(250)
                ->each(function (RecipeReport $report) use ($output): void {
                    fputcsv($output, [
                        $report->id,
                        $report->recipe_id,
                        $this->csvCell($report->recipe->name),
                        $this->csvCell($report->recipe->category),
                        $this->csvCell($report->reason),
                        $report->resolved_at === null ? 'needs_review' : 'resolved',
                        $this->csvCell($report->details),
                        $report->created_at?->toIso8601String(),
                        $report->resolved_at?->toIso8601String(),
                        $this->csvCell($report->resolution_note),
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
