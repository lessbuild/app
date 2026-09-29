<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Reports\WeeklyReport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The Monday project report email. Sent synchronously by the report command, which records the outcome.
 *
 * @phpstan-import-type Report from WeeklyReport
 */
final class WeeklyReportNotification extends Notification
{
    /**
     * Create a new WeeklyReportNotification instance.
     *
     * @param  Report  $report  The account's week, built by WeeklyReport.
     */
    public function __construct(public readonly array $report) {}

    /**
     * Get the notification's delivery channels: email only.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the email: a line per measure for each project, the week covered, and where to turn the report off.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $report = $this->report;
        $deploys = array_sum(array_column($report['projects'], 'deploys'));
        $incidents = array_sum(array_column($report['projects'], 'incidents'));
        $message = (new MailMessage)
            ->subject(__(':account last week: :deploys, :incidents', [
                'account' => $report['account'],
                'deploys' => trans_choice(':count deploy|:count deploys', $deploys, ['count' => $deploys]),
                'incidents' => trans_choice(':count incident|:count incidents', $incidents, ['count' => $incidents]),
            ]))
            ->greeting(__('Your week at :account', ['account' => $report['account']]))
            ->line(__(':from to :until (UTC).', ['from' => $report['from']->format('D j M'), 'until' => $report['until']->subDay()->format('D j M')]));

        foreach ($report['projects'] as $project) {
            $message->line('**['.$project['name'].']('.$project['url'].')**');
            $facts = [];
            if ($project['deploys'] > 0) {
                $facts[] = trans_choice(':count deploy|:count deploys', $project['deploys'], ['count' => $project['deploys']])
                    .($project['deploys_failed'] > 0 ? ' ('.__(':count failed', ['count' => $project['deploys_failed']]).')' : '');
            }
            if ($project['uptime'] !== null) {
                $facts[] = __(':uptime% uptime', ['uptime' => rtrim(rtrim(number_format($project['uptime'], 2), '0'), '.')]);
            }
            if ($project['incidents'] > 0 || $project['incidents_open'] > 0) {
                $facts[] = trans_choice(':count incident|:count incidents', $project['incidents'], ['count' => $project['incidents']])
                    .($project['incidents_open'] > 0 ? ' ('.__(':count still open', ['count' => $project['incidents_open']]).')' : '');
            }
            if ($project['visits'] !== null) {
                $facts[] = trans_choice(':count visit|:count visits', $project['visits'], ['count' => number_format($project['visits'])]).$this->change($project['visits'], $project['visits_before']);
            }
            $message->line(implode(' · ', $facts));
            if ($project['top_pages'] !== []) {
                $message->line(__('Top pages: :pages', ['pages' => implode(', ', array_map(fn (array $page): string => $page['path'].' ('.number_format($page['views']).')', $project['top_pages']))])
                    .($project['top_source'] !== null ? ' · '.__('Top source: :source', ['source' => $project['top_source']]) : ''));
            }
        }

        return $message
            ->action(__('Open BuildPusher'), route('dashboard'))
            ->line(__('You get this every Monday. [Change your emails](:url)', ['url' => route('settings.notifications')]));
    }

    /**
     * Describe the change from the week before, e.g. " (up 12%)", or nothing when there's no earlier week to compare.
     *
     * @param  int  $now
     * @param  int|null  $before
     * @return string
     */
    private function change(int $now, ?int $before): string
    {
        if ($before === null || $before === 0) {
            return '';
        }
        $percent = (int) round(($now - $before) / $before * 100);

        return match (true) {
            $percent > 0 => ' ('.__('up :percent%', ['percent' => $percent]).')',
            $percent < 0 => ' ('.__('down :percent%', ['percent' => abs($percent)]).')',
            default => ' ('.__('same as the week before').')',
        };
    }
}
