<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ShowTraceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'environment' => ['nullable', 'string', 'size:26'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
