<?php

namespace App\Core\Http\Requests\Deployer;

use Illuminate\Foundation\Http\FormRequest;

final class DeployerProjectConfigurationPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'environment_q' => ['nullable', 'string', 'max:100'],
            'environment_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
