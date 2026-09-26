<?php

namespace App\Modules\Deployer\Actions\Recipe;

use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\RecipeReportQuery;

class ReviewRecipeReportUpdatesAction
{
    public function __construct(private readonly RecipeReportQuery $reports) {}

    /**
     * Mark the reporter's unread gallery report updates as reviewed.
     *
     * @param  User  $reporter  The authenticated reporter whose notifications are changed.
     * @return int The number of report-update notifications marked as reviewed.
     */
    public function handle(User $reporter): int
    {
        return $this->reports->unreadUpdates($reporter)->update(['read_at' => now()]);
    }
}
