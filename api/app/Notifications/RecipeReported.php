<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\RecipeReport;

/** Someone reported one of the account's gallery recipes. Sent to its owners and admins. */
final class RecipeReported extends InboxNotification
{
    /**
     * Create a new RecipeReported instance.
     *
     * A published recipe was reported.
     *
     * @param  RecipeReport  $report  The report.
     */
    public function __construct(private readonly RecipeReport $report) {}

    /**
     * Get the headline, naming the recipe and the reason.
     *
     * @return string
     */
    protected function title(): string
    {
        return __(':recipe was reported: :reason', ['recipe' => $this->report->recipe->name, 'reason' => $this->report->reason->label()]);
    }

    /**
     * Say what to do next.
     *
     * @return string
     */
    protected function body(): string
    {
        return __('Review the report and resolve it, with a note for the person who filed it.');
    }

    /**
     * Get the account's recipe reports.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('account.recipes.reports');
    }

    /**
     * Get the account that published the recipe.
     *
     * @return string
     */
    protected function accountId(): string
    {
        return $this->report->recipe->account_id;
    }
}
