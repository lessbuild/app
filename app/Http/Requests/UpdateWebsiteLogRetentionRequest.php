<?php

namespace App\Http\Requests;

use App\Models\Website;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebsiteLogRetentionRequest extends FormRequest
{
    /**
     * Allow retention changes only for a route-bound website the actor can update.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $website = $this->route('website');

        return $user !== null && $website instanceof Website && $user->can('update', $website);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'log_retention_lines' => ['required', 'integer', Rule::in([100, 500, 1000, 5000, 10000])],
        ];
    }
}
