<?php

namespace App\Notifications;

use App\Models\Recipe;
use App\Models\RecipeReport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RecipeReportStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Recipe $recipe,
        private readonly RecipeReport $report,
        private readonly string $state,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{category: string, resource_id: int, report_id: int, title: string, message: string, status: string} */
    public function toDatabase(object $notifiable): array
    {
        $resolved = $this->state === 'resolved';
        $hasNote = $resolved && filled($this->report->resolution_note);

        return [
            'category' => 'gallery',
            'resource_id' => (int) $this->recipe->getKey(),
            'report_id' => (int) $this->report->getKey(),
            'title' => $resolved ? 'Gallery report resolved' : 'Gallery report reopened',
            'message' => str($resolved
                ? 'The contributor resolved your report for "'.$this->recipe->name.'".'
                    .($hasNote ? ' A resolution note is available.' : '')
                : 'The contributor reopened your report for "'.$this->recipe->name.'".')
                ->limit(500, '')
                ->toString(),
            'status' => NotificationInbox::STATUS_INFO,
        ];
    }
}
