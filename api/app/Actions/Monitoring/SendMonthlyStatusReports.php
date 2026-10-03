<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\StatusPage;
use App\Models\StatusSubscription;
use App\Notifications\StatusMonthlyReportNotification;
use App\Queries\Monitoring\MonthlyUptimeQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

final class SendMonthlyStatusReports
{
    /**
     * Create a new SendMonthlyStatusReports instance.
     *
     * @param  MonthlyUptimeQuery  $uptime  Builds each report.
     */
    public function __construct(private readonly MonthlyUptimeQuery $uptime) {}

    /**
     * Email last month's uptime report to the confirmed subscribers of each published page that asked for it, once per
     * month, from 9am UTC on the 1st (or a day or two later if sending was down). Returns how many pages sent.
     *
     * @return int
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        if ($now->day > 3 || ($now->day === 1 && $now->hour < 9)) {
            return 0;
        }
        $month = $now->subMonthNoOverflow()->startOfMonth();
        $sent = 0;
        StatusPage::query()->where('published', true)->where('monthly_report', true)
            ->where(fn ($query) => $query->whereNull('last_monthly_report')->orWhere('last_monthly_report', '!=', $month->format('Y-m')))
            ->each(function (StatusPage $page) use ($month, &$sent): void {
                $page->forceFill(['last_monthly_report' => $month->format('Y-m')])->save();
                if (CarbonImmutable::parse($page->created_at ?? now())->greaterThan($month->endOfMonth())) {
                    return;
                }
                $report = $this->uptime->handle($page, $month);
                StatusSubscription::query()->where('status_page_id', $page->id)->whereNotNull('verified_at')
                    ->each(fn (StatusSubscription $subscription) => Notification::route('mail', $subscription->email)->notify(new StatusMonthlyReportNotification($page, $subscription, $report)));
                $sent++;
            });

        return $sent;
    }
}
