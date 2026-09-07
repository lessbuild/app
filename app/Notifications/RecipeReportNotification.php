<?php

namespace App\Notifications;

use App\Models\Recipe;
use App\Models\RecipeReport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RecipeReportNotification extends Notification
{
    use Queueable;

    /**
     * Capture the reported recipe and report record for the moderator inbox.
     *
     * @param  Recipe  $recipe  Gallery recipe referenced by the notification.
     * @param  RecipeReport  $report  Moderation report associated with the recipe.
     */
    public function __construct(
        private readonly Recipe $recipe,
        private readonly RecipeReport $report,
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
     * Build the moderator inbox payload identifying the report and its recipe without including report body text.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return array{category: string, resource_id: int, title: string, message: string, status: string} Recipe-report inbox data with the report resource ID and informational status.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'recipe',
            'resource_id' => (int) $this->report->getKey(),
            'title' => 'Community recipe feedback',
            'message' => str('"'.$this->recipe->name.'" has a community report that needs review.')
                ->limit(500, '')
                ->toString(),
            'status' => NotificationInbox::STATUS_INFO,
        ];
    }
}
