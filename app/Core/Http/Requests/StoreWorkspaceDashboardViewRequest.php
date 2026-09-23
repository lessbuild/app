<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkspaceDashboardViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'visibility' => ['required', Rule::in(['personal', 'workspace'])],
            'product' => ['required', Rule::in(['all', 'deployer', 'monitor', 'analytics'])],
            'pinned_only' => ['required', 'boolean'],
        ];
    }
}
