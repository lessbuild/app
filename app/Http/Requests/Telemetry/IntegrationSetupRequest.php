<?php

declare(strict_types=1);

namespace App\Http\Requests\Telemetry;

use App\Support\Telemetry\IntegrationSetupGuide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IntegrationSetupRequest extends FormRequest
{
    /**
     * An optional stack, one of those the setup guide knows.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'stack' => ['nullable', Rule::in(array_keys(IntegrationSetupGuide::STACKS))],
        ];
    }

    /**
     * The chosen stack, or the stack-neutral default.
     */
    public function stack(): string
    {
        return $this->validated('stack') ?? IntegrationSetupGuide::DEFAULT_STACK;
    }
}
