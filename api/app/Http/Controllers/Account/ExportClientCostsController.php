<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Client;
use App\Queries\Agency\ClientReportQuery;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportClientCostsController
{
    /**
     * Download a CSV of what each client's projects cost this month, with each client's markup, for invoicing.
     *
     * @param  Account  $account
     * @param  ClientReportQuery  $reports
     * @return StreamedResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ClientReportQuery $reports): StreamedResponse
    {
        $month = now('UTC')->format('Y-m');
        $clients = Client::query()->where('account_id', $account->id)->orderBy('name')->get();

        return response()->streamDownload(function () use ($clients, $reports, $month): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['client', 'project', 'currency', 'cost_with_markup', 'markup_percent'], escape: '');
            foreach ($clients as $client) {
                foreach ($reports->handle($client, $month)['projects'] as $project) {
                    foreach ($project['cost'] as $currency => $amount) {
                        fputcsv($out, [$client->name, $project['name'], $currency, number_format($amount, 2, '.', ''), $client->markup_percent], escape: '');
                    }
                }
            }
            fclose($out);
        }, "client-costs-{$month}.csv", ['Content-Type' => 'text/csv']);
    }
}
