<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityExporter
{
    public function __construct(private readonly ActivityQuery $activity) {}

    /**
     * Stream filtered user activity as a private, spreadsheet-safe CSV.
     *
     * @param  array{search: ?string, category: ?string, date_from: ?string, date_to: ?string}  $filters  Validated activity filters.
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-activity-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Event ID',
                'Category',
                'Activity',
                'Resource type',
                'Resource ID',
                'Recorded at',
            ], ',', '"', '');

            $this->activity->for($user, $filters)
                ->latest('id')
                ->lazy(250)
                ->each(function (Event $event) use ($output): void {
                    fputcsv($output, [
                        $event->id,
                        $this->csvCell($event->category),
                        $this->csvCell($event->event),
                        $this->csvCell(class_basename($event->parentable_type)),
                        $event->parentable_id,
                        $event->created_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Escape activity text that could be interpreted as a spreadsheet formula. */
    private function csvCell(?string $value): ?string
    {
        return CsvCell::escape($value);
    }
}
