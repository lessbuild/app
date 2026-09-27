<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use App\Enums\IngestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchIngestReceiptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(IngestStatus::class)],
            'page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }
}
