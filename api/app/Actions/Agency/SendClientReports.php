<?php

declare(strict_types=1);

namespace App\Actions\Agency;

use App\Models\Client;
use App\Notifications\ClientReportNotification;
use App\Queries\Agency\ClientReportQuery;
use App\Services\Accounts\AccountBranding;
use Illuminate\Support\Facades\Notification;

final class SendClientReports
{
    /**
     * Create a new SendClientReports instance.
     *
     * @param  ClientReportQuery  $reports  Builds each report.
     * @param  AccountBranding  $branding  Names the sender.
     */
    public function __construct(private readonly ClientReportQuery $reports, private readonly AccountBranding $branding) {}

    /**
     * Email last month's report to each client that wants one, once per month, in the agency's name.
     *
     * @return int clients sent to
     */
    public function handle(): int
    {
        $month = now('UTC')->subMonthNoOverflow()->format('Y-m');
        $sent = 0;
        Client::query()->where('monthly_report', true)->where(fn ($query) => $query->whereNull('last_report_month')->orWhere('last_report_month', '<', $month))
            ->with('account')->each(function (Client $client) use ($month, &$sent): void {
                if ($client->emails === [] || $client->project_ids === []) {
                    return;
                }
                $report = $this->reports->handle($client, $month);
                $sender = $this->branding->for($client->account)['name'] ?? $client->account->name;
                foreach ($client->emails as $email) {
                    Notification::route('mail', $email)->notify(new ClientReportNotification($sender, $client->name, $report));
                }
                $client->forceFill(['last_report_month' => $month])->save();
                $sent++;
            });

        return $sent;
    }
}
