<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use App\Queries\Telemetry\DependencyMapQuery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchDependencyMapRequest extends FormRequest
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

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'range' => ['nullable', 'string', Rule::in(array_keys(DependencyMapQuery::RANGES))],
            'environment' => ['nullable', 'string', 'size:26'],
        ];
    }

    /** @return array{range: string, environment?: int} */
    public function filters(): array
    {
        return array_replace(['range' => '24h'], array_filter(
            $this->validated(),
            fn (mixed $value): bool => $value !== null && $value !== '',
        ));
    }
}
