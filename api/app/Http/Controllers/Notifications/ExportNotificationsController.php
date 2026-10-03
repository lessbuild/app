<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Requests\Notifications\InboxRequest;
use App\Models\User;
use App\Queries\Notifications\InboxQuery;
use App\Support\SpreadsheetCell;
use Illuminate\Container\Attributes\CurrentUser;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The person's filtered notifications as CSV. Cells a spreadsheet would treat as formulas are escaped. */
final class ExportNotificationsController
{
    /**
     * Stream the notifications matching the inbox's filters as a UTF-8 CSV, newest first.
     *
     * @param  InboxRequest  $request
     * @param  User  $user
     * @param  InboxQuery  $inbox
     * @return StreamedResponse
     */
    public function __invoke(InboxRequest $request, #[CurrentUser] User $user, InboxQuery $inbox): StreamedResponse
    {
        $filters = $request->filters(array_keys($inbox->types($user)));

        return response()->streamDownload(function () use ($user, $filters, $inbox): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['when', 'title', 'text', 'read'], ',', '"', '');
            foreach ($inbox->export($user, $filters) as $item) {
                fputcsv($out, [$item->at->toIso8601String(), SpreadsheetCell::text($item->title), SpreadsheetCell::text($item->body), $item->read ? 'yes' : 'no'], ',', '"', '');
            }
            fclose($out);
        }, 'notifications-'.now('UTC')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
