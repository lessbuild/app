<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\ReportRecipe;
use App\Enums\RecipeReportReason;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ReportRecipeController
{
    /**
     * Report a gallery recipe or change the report (PUT), or withdraw it (DELETE), and return to the recipe.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $recipe
     * @param  ReportRecipe  $report
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $recipe, ReportRecipe $report): RedirectResponse
    {
        $target = Recipe::query()->published()->findOrFail(ctype_digit($recipe) ? (int) $recipe : 0);
        if ($request->isMethod('PUT')) {
            $request->validate(['reason' => ['required', Rule::enum(RecipeReportReason::class)], 'details' => ['nullable', 'string', 'max:2000']]);
            $details = trim($request->string('details')->toString());
            $report->handle($user, $target, RecipeReportReason::from($request->string('reason')->toString()), $details === '' ? null : $details);
        } else {
            $report->handle($user, $target, null);
        }

        return to_route('recipes.gallery.show', $target->id)->with('status', $request->isMethod('PUT') ? __('Thanks. The publisher has been told.') : __('Report withdrawn.'));
    }
}
