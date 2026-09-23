<?php

namespace App\Modules\Deployer\Http\Requests;

use App\Modules\Deployer\Data\ObservabilityContextFilters;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Repository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ObservabilityContextRequest extends FormRequest
{
    /** Authorize the environment policy before validating the investigation window. */
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        return $environment instanceof Environment
            && ($this->user()?->can('view', $environment) ?? false);
    }

    /** @return array<string, list<mixed>> Validate only the finite read filters. */
    public function rules(): array
    {
        return [
            'window' => ['required', Rule::in(array_keys(ObservabilityContextFilters::WINDOWS))],
            'service' => ['required', Rule::in(array_merge(['all'], $this->serviceIds()))],
            'deployment' => ['required', Rule::in(array_keys(ObservabilityContextFilters::DEPLOYMENTS))],
            'severity' => ['required', Rule::in(ObservabilityContextFilters::SEVERITIES)],
        ];
    }

    /**
     * Return the validated context window as an immutable query boundary.
     *
     * @return ObservabilityContextFilters Normalized environment evidence filters.
     */
    public function filters(): ObservabilityContextFilters
    {
        /** @var '24h'|'7d'|'30d' $window */
        $window = $this->validated('window');
        $service = $this->validated('service');
        /** @var 'all'|'active'|'successful'|'unsuccessful' $deployment */
        $deployment = $this->validated('deployment');
        /** @var 'all'|'minor'|'major'|'critical' $severity */
        $severity = $this->validated('severity');

        return ObservabilityContextFilters::fromValues(
            window: $window,
            serviceId: $service === 'all' ? null : (int) $service,
            deployment: $deployment,
            severity: $severity,
        );
    }

    /** Default the first context visit to broad, unfiltered evidence while rejecting explicit null values. */
    protected function prepareForValidation(): void
    {
        $defaults = [];

        if ($this->missing('window')) {
            $defaults['window'] = '24h';
        }
        if ($this->missing('service')) {
            $defaults['service'] = 'all';
        }
        if ($this->missing('deployment')) {
            $defaults['deployment'] = 'all';
        }
        if ($this->missing('severity')) {
            $defaults['severity'] = 'all';
        }

        if ($defaults !== []) {
            $this->merge($defaults);
        }
    }

    /** @return list<string> Repository service IDs belonging to the selected environment's website. */
    private function serviceIds(): array
    {
        $environment = $this->route('environment');
        if (! $environment instanceof Environment || ! $environment->website_id) {
            return [];
        }

        return Repository::query()
            ->where('organization_id', $environment->project->organization_id)
            ->where('website_id', $environment->website_id)
            ->pluck('id')
            ->map(static fn (int|string $id): string => (string) $id)
            ->all();
    }
}
