<?php

namespace App\Services;

use App\Models\User;
use App\Support\CsvCell;
use Illuminate\Notifications\DatabaseNotification;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotificationInboxExporter
{
    public function __construct(private readonly NotificationInboxQuery $inbox) {}

    /**
     * Stream the user's filtered notification metadata as private CSV, excluding unsupported payload value types.
     *
     * @param  array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string}  $filters  Validated inbox filters.
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-notifications-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Notification ID',
                'Category',
                'Title',
                'Message',
                'Status',
                'State',
                'Resource ID',
                'Created at',
                'Read at',
            ], ',', '"', '');

            $this->inbox->for($user, $filters)
                ->latest('created_at')
                ->lazy(250)
                ->each(function (DatabaseNotification $notification) use ($output): void {
                    fputcsv($output, [
                        $notification->id,
                        $this->csvCell($this->dataValue($notification, 'category')),
                        $this->csvCell($this->dataValue($notification, 'title')),
                        $this->csvCell($this->dataValue($notification, 'message')),
                        $this->csvCell($this->dataValue($notification, 'status')),
                        $notification->read_at === null ? 'unread' : 'read',
                        $this->csvCell($this->dataValue($notification, 'resource_id')),
                        $notification->created_at?->toIso8601String(),
                        $notification->read_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Read an exportable notification payload value without exposing arrays or objects. */
    private function dataValue(DatabaseNotification $notification, string $key): string|int|null
    {
        $value = $notification->data[$key] ?? null;

        return is_string($value) || is_int($value) ? $value : null;
    }

    /** Escape values that could be interpreted as spreadsheet formulas. */
    private function csvCell(string|int|null $value): ?string
    {
        return CsvCell::escape($value);
    }
}
