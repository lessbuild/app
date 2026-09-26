<?php

namespace App\Core\Http\Requests\Deployer;

use Illuminate\Foundation\Http\FormRequest;

final class DeployerConfigurationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
