<?php

namespace App\Core\Http\Requests;

use App\Core\Enums\ProductKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProjectResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'product' => ['required', 'string', Rule::enum(ProductKey::class)],
            'resource_id' => ['required', 'string', 'max:191'],
            'environment_id' => ['nullable', 'string', 'ulid'],
        ];
    }
}
