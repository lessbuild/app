<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveRecipeReportsRequest extends FormRequest
{
    /**
     * Authentication is supplied by the route; report ownership is checked after the selection is locked.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** Keep validation failures in the existing resolve-specific session error bag. */
    protected $errorBag = 'bulkResolve';

    /**
     * Require a bounded selection of distinct report IDs.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reports' => ['required', 'array', 'min:1', 'max:20'],
            'reports.*' => ['required', 'integer', 'distinct:strict'],
        ];
    }
}
