<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Data\Analytics\GoalDetails;
use Illuminate\Foundation\Http\FormRequest;

final class GoalRequest extends FormRequest
{
    /**
     * Get the validation rules: a goal's name, kind (path or event), match type and value, and whether it's active.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', 'in:path,event'],
            'match_type' => ['required', 'string', 'in:exact,prefix'],
            'match_value' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Build the goal's settings.
     *
     * @return GoalDetails
     */
    public function toDetails(): GoalDetails
    {
        return new GoalDetails(
            name: $this->string('name')->toString(),
            kind: $this->string('kind')->toString(),
            matchType: $this->string('match_type')->toString(),
            matchValue: $this->string('match_value')->toString(),
            active: $this->boolean('active'),
        );
    }
}
