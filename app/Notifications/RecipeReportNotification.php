<?php

namespace App\Notifications;

use App\Models\Recipe;
use App\Models\RecipeReport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RecipeReportNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Recipe $recipe,
        private readonly RecipeReport $report,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{category: string, resource_id: int, title: string, message: string, status: string} */
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
