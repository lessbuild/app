<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RecipeReport;
use App\Models\User;

/** A report is the reporter's to see and the publisher's to resolve. */
final class RecipeReportPolicy
{
    /**
     * Create a new RecipeReportPolicy instance.
     *
     * Decides report access through the recipe's publishing rules.
     *
     * @param  RecipePolicy  $recipes  Decides who publishes the reported recipe.
     */
    public function __construct(private readonly RecipePolicy $recipes) {}

    /**
     * Determine whether the user can resolve or reopen a report: the reported recipe's publishers.
     *
     * @param  User  $user
     * @param  RecipeReport  $report
     * @return bool
     */
    public function resolve(User $user, RecipeReport $report): bool
    {
        return $this->recipes->publish($user, $report->recipe);
    }
}
