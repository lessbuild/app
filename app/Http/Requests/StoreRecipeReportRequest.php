<?php

namespace App\Http\Requests;

use App\Models\RecipeReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecipeReportRequest extends FormRequest
{
    /**
     * Route authentication is handled by the web route; publication and authorship checks remain in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the issue type and optional bounded private report details.
     *
     * @return array<string, list<string|Rule>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(RecipeReport::REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Return validated details with the existing whitespace normalization semantics.
     */
    public function details(): ?string
    {
        $details = $this->validated('details');

        return filled($details)
            ? str($details)->trim()->toString()
            : null;
    }
}
