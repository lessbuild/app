<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchIngestReceiptsRequest extends FormRequest
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
