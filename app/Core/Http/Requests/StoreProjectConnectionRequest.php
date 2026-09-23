<?php

namespace App\Core\Http\Requests;

use App\Core\Enums\ProjectConnectionCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProjectConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'source_resource_id' => ['required', 'ulid'],
            'target_resource_id' => ['required', 'ulid', 'different:source_resource_id'],
            'capabilities' => ['required', 'array', 'min:1', 'max:4'],
            'capabilities.*' => ['required', 'string', 'distinct', Rule::enum(ProjectConnectionCapability::class)],
        ];
    }
}
