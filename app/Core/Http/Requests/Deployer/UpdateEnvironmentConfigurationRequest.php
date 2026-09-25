<?php

namespace App\Core\Http\Requests\Deployer;

use App\Modules\Deployer\Models\Environment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class UpdateEnvironmentConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // A type change needs a coordinated update of the mapped Core environment.
            'type' => ['prohibited'],
            'branch' => ['required', 'string', 'max:255'],
            'minimum_replicas' => ['required', 'integer', 'between:1,20'],
            'maximum_replicas' => ['required', 'integer', 'between:1,20', 'gte:minimum_replicas'],
            'hibernate_after_minutes' => ['nullable', 'integer', Rule::in([5, 15, 30, 60, 120, 1440])],
            'post_deployment_observation_minutes' => ['nullable', 'integer', Rule::in(Environment::POST_DEPLOYMENT_OBSERVATION_MINUTES)],
        ];
    }

    /** Do not flash forged commands, placement IDs, or secret values into session input. */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(redirect()->back()->withErrors($validator, $this->errorBag));
    }
}
