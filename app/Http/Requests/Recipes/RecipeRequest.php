<?php

declare(strict_types=1);

namespace App\Http\Requests\Recipes;

use App\Enums\RecipeCategory;
use App\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A recipe's name, description, category and script. Authorisation happens in SaveRecipe. */
final class RecipeRequest extends FormRequest
{
    /**
     * Get the validation rules: a name, an optional description, a category, and a script of at most 64 KB.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['required', Rule::enum(RecipeCategory::class)],
            'script' => ['required', 'string', 'max:'.Recipe::MAX_SCRIPT_BYTES],
        ];
    }

    /**
     * Get the recipe's fields, with line endings normalised to Unix ones for the server.
     *
     * @return array{name: string, description: string|null, category: RecipeCategory, script: string}
     */
    public function recipe(): array
    {
        $description = trim($this->string('description')->toString());

        return [
            'name' => trim($this->string('name')->toString()),
            'description' => $description === '' ? null : $description,
            'category' => RecipeCategory::from($this->string('category')->toString()),
            'script' => str_replace(["\r\n", "\r"], "\n", $this->string('script')->toString()),
        ];
    }
}
