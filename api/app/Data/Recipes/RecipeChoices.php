<?php

declare(strict_types=1);

namespace App\Data\Recipes;

use App\Enums\RecipeCategory;
use App\Enums\RecipeReportReason;

final readonly class RecipeChoices
{
    /**
     * The recipe categories, for forms and filters.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function categories(): array
    {
        return array_map(fn (RecipeCategory $category): array => ['value' => $category->value, 'label' => $category->label()], RecipeCategory::cases());
    }

    /**
     * The reasons a gallery recipe can be reported for.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function reasons(): array
    {
        return array_map(fn (RecipeReportReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()], RecipeReportReason::cases());
    }
}
