<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Client;
use App\Queries\Agency\ClientReportQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/account/clients/{client}/report?month=YYYY-MM`. */
final class ShowClientReportController
{
    /**
     * Return a client's report for a month (last month by default): each project's uptime, incidents, releases,
     * visitors and cost with the markup.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  Client  $client
     * @param  ClientReportQuery  $reports
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, Client $client, ClientReportQuery $reports): JsonResponse
    {
        abort_if($client->account_id !== $account->id, 404);
        $month = $request->string('month')->toString();
        $month = preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month) === 1 ? $month : now('UTC')->subMonthNoOverflow()->format('Y-m');

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'client' => ['id' => $client->id, 'name' => $client->name, 'markupPercent' => $client->markup_percent],
            'report' => $reports->handle($client, $month),
        ]);
    }
}
