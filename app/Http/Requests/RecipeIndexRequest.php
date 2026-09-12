<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecipeIndexRequest extends FormRequest
{
    /** Authentication is supplied by the authenticated web route; the query is scoped to the current user. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate normalized recipe-inventory filters without turning invalid values into user-facing errors.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'usage' => ['nullable', Rule::in(['in_use', 'unused'])],
        ];
    }

    /**
     * Return the validated recipe-inventory filter contract.
     *
     * @return array{search: ?string, usage: ?string}
     */
    public function filters(): array
    {
        /** @var array{search: ?string, usage: ?string} */
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
        $usage = $this->string('usage')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'usage' => in_array($usage, ['in_use', 'unused'], true) ? $usage : null,
        ];
    }
}
