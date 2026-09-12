<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RecipeReportPolicy
{
    /**
     * Allow only the reporter to view the private report status, concealing other reports as absent.
     *
     * @param  User  $user  The account requesting private report history.
     * @param  RecipeReport  $report  The report whose private status is requested.
     * @return Response The authorization result, deliberately using 404 for foreign reports.
     */
    public function view(User $user, RecipeReport $report): Response
    {
        return (int) $report->user_id === (int) $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Allow the recipe contributor to review a matching report while preserving route-scoped 404 concealment.
     *
     * @param  User  $user  The account attempting the contributor operation.
     * @param  RecipeReport  $report  The report supplied by the route.
     * @param  Recipe  $recipe  The recipe supplied by the route.
     * @return Response The authorization result, deliberately using 404 for foreign or mismatched resources.
     */
    public function review(User $user, RecipeReport $report, Recipe $recipe): Response
    {
        return (int) $recipe->user_id === (int) $user->id
            && (int) $report->recipe_id === (int) $recipe->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
