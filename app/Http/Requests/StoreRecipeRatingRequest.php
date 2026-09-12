<?php

namespace App\Http\Requests;

use App\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecipeRatingRequest extends FormRequest
{
    /**
     * Preserve the published-resource 404 and rating-policy checks before validating the rating value.
     */
    public function authorize(): bool
    {
        $recipe = $this->route('recipe');
        if (! $recipe instanceof Recipe) {
            return false;
        }

        abort_unless($recipe->is_published && $recipe->published_at !== null, 404);

        return $this->user()?->can('rate', $recipe) ?? false;
    }

    /**
     * Validate the requested gallery rating.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
        ];
    }

    /** Return the explicitly validated rating value. */
    public function rating(): int
    {
        return (int) $this->validated('rating');
    }
}
