<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\RecipeReport;

/** The publisher resolved someone's report on a gallery recipe. Sent to the reporter. */
final class RecipeReportResolved extends InboxNotification
{
    /**
     * Create a new RecipeReportResolved instance.
     *
     * A report was resolved.
     *
     * @param  RecipeReport  $report  The report.
     */
    public function __construct(private readonly RecipeReport $report) {}

    /**
     * Get the headline, naming the recipe.
     *
     * @return string
     */
    protected function title(): string
    {
        return __('Your report on :recipe was resolved', ['recipe' => $this->report->recipe->name]);
    }

    /**
     * Get the publisher's note, or say there wasn't one.
     *
     * @return string
     */
    protected function body(): string
    {
        return $this->report->resolution_note ?? __('The publisher didn’t add a note.');
    }

    /**
     * Get the reporter's list of reports.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('recipes.gallery.reports');
    }
}
