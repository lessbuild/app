<?php

namespace App\Modules\Monitor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreIngestTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('environment'));

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'between:1,365'],
        ];
    }
}
