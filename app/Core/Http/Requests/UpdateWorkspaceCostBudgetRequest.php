<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateWorkspaceCostBudgetRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'monthly_infrastructure_budget' => ['nullable', 'numeric', 'between:1,1000000'],
        ];
    }
}
