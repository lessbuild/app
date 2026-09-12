<?php

namespace App\Http\Requests;

use App\Models\Environment;
use App\Services\Entitlements;
use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeploymentScheduleRequest extends FormRequest
{
    /**
     * Preserve environment authorization and the paid-feature denial before schedule validation.
     */
    public function authorize(): bool
    {
        $environment = $this->route('environment');
        if (! $environment instanceof Environment
            || ! ($this->user()?->can('update', $environment) ?? false)) {
            return false;
        }

        app(Entitlements::class)->enforce($environment->project->organization, 'scheduled_deployments');

        return true;
    }

    /**
     * Validate a deployment schedule's name, five-part cron expression and IANA timezone.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'cron_expression' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
                        $fail(__('Enter a valid five-part cron expression.'));
                    }
                },
            ],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
        ];
    }
}
