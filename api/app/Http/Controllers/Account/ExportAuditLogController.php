<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\AuditAction;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\AuditLogRequest;
use App\Models\Account;
use App\Queries\Audit\AccountAuditLogQuery;
use App\Queries\Projects\ProjectSwitcherQuery;
use App\Support\SpreadsheetCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The filtered audit log as CSV. Cells a spreadsheet would treat as formulas are escaped. */
final class ExportAuditLogController
{
    /**
     * Stream the entries matching the page's filters as a UTF-8 CSV, newest first.
     *
     * @param  Account  $account
     * @param  AuditLogRequest  $request
     * @param  AccountAuditLogQuery  $query
     * @param  ProjectSwitcherQuery  $projects
     * @return StreamedResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, AuditLogRequest $request, AccountAuditLogQuery $query, ProjectSwitcherQuery $projects): StreamedResponse
    {
        $filters = $request->filters(array_column($projects->handle($account, 500), 'id'), $account->members()->pluck('users.id')->map(fn (mixed $id): string => (string) $id)->values()->all());

        return response()->streamDownload(function () use ($account, $filters, $query): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['when', 'who', 'email', 'category', 'what', 'ip_address', 'device'], ',', '"', '');
            foreach ($query->export($account, $filters) as $entry) {
                fputcsv($out, [
                    $entry->at->toIso8601String(), SpreadsheetCell::text($entry->actor), SpreadsheetCell::text($entry->actorEmail ?? ''), AuditAction::CATEGORIES[$entry->category] ?? $entry->category,
                    SpreadsheetCell::text($entry->description), $entry->ipAddress, SpreadsheetCell::text($entry->device ?? ''),
                ], ',', '"', '');
            }
            fclose($out);
        }, 'audit-log-'.now('UTC')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
