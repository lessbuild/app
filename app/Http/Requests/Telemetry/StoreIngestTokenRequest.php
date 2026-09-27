<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIngestTokenRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'between:1,365'],
        ];
    }
}
