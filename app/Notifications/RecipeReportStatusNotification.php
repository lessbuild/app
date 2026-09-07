<?php

namespace App\Notifications;

use App\Models\Recipe;
use App\Models\RecipeReport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RecipeReportStatusNotification extends Notification
{
    use Queueable;

    /**
     * Capture the recipe report and its latest moderation state for the reporter inbox.
     *
     * @param  Recipe  $recipe  Gallery recipe referenced by the notification.
     * @param  RecipeReport  $report  Moderation report associated with the recipe.
     * @param  string  $state  The literal resolved indicates resolution; other values are presented as reopened.
     */
    public function __construct(
        private readonly Recipe $recipe,
        private readonly RecipeReport $report,
        private readonly string $state,
    ) {}

    /**
     * Deliver this notification through Laravel's database channel.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return list<string> The single database channel used for this notification.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build reporter inbox data for the recipe and report, indicating resolution or reopening and whether a moderator note exists.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return array{category: string, resource_id: int, report_id: int, title: string, message: string, status: string} Gallery inbox data with recipe/report identifiers, moderation display text, and informational status.
     */
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
