<?php

namespace App\Http\Requests;

use App\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecipeGalleryIndexRequest extends FormRequest
{
    /** Authentication is supplied by the authenticated web route; gallery data is scoped to the current actor. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate normalized gallery filters without turning invalid values into user-facing errors.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::in(Recipe::CATEGORIES)],
            'scope' => ['required', Rule::in($this->scopes())],
            'sort' => ['required', Rule::in(['recent', 'popular', 'top_rated'])],
        ];
    }

    /**
     * Return the validated published-gallery filter contract.
     *
     * @return array{search: ?string, category: ?string, scope: string, sort: string}
     */
    public function filters(): array
    {
        /** @var array{search: ?string, category: ?string, scope: string, sort: string} */
        return $this->validated();
    }

    /**
     * Validate normalized values without replacing query parameters used by pagination links.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $search = str($this->string('search')->toString())->trim()->limit(100, '')->toString();
        $category = $this->string('category')->toString();
        $scope = $this->string('scope')->toString();
        $sort = $this->string('sort')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'category' => in_array($category, Recipe::CATEGORIES, true) ? $category : null,
            'scope' => in_array($scope, $this->scopes(), true) ? $scope : 'all',
            'sort' => in_array($sort, ['recent', 'popular', 'top_rated'], true) ? $sort : 'recent',
        ];
    }

    /** @return list<string> Gallery scopes accepted by the page and its filter links. */
    private function scopes(): array
    {
        return [
            'all',
            'favorites',
            'reported',
            'reports_open',
            'reports_resolved',
            'installed',
            'updates',
            'mine',
        ];
    }
}
