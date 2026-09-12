<?php

namespace App\Http\Requests;

use App\Models\Build;
use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BuildIndexRequest extends FormRequest
{
    /** Authentication is supplied by the authenticated web route; inventory queries scope through the current user. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate normalized build-history filters without changing the existing silent fallback behavior.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'repository_id' => ['nullable', 'integer', 'min:1'],
            'website_id' => ['nullable', 'integer', 'min:1'],
            'server_id' => ['nullable', 'integer', 'min:1'],
            'provider_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in($this->statuses())],
            'trigger' => ['nullable', Rule::in($this->triggers())],
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', Rule::in(['1'])],
            'latest' => ['nullable', Rule::in(['1'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Return the validated deployment-history filter contract.
     *
     * @return array{repository_id: ?int, website_id: ?int, server_id: ?int, provider_id: ?int, status: ?string, trigger: ?string, search: ?string, active: ?string, latest: ?string, date_from: ?string, date_to: ?string}
     */
    public function filters(): array
    {
        /** @var array{repository_id: ?int, website_id: ?int, server_id: ?int, provider_id: ?int, status: ?string, trigger: ?string, search: ?string, active: ?string, latest: ?string, date_from: ?string, date_to: ?string} */
        return $this->validated();
    }

    /**
     * Validate normalized values without replacing query parameters used by the controller's pagination links.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $search = str($this->string('search')->toString())->trim()->limit(100, '')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $this->string('date_from')->toString(),
            $this->string('date_to')->toString(),
        );

        return [
            'repository_id' => $this->positiveInteger('repository_id'),
            'website_id' => $this->positiveInteger('website_id'),
            'server_id' => $this->positiveInteger('server_id'),
            'provider_id' => $this->positiveInteger('provider_id'),
            'status' => in_array($this->string('status')->toString(), $this->statuses(), true)
                ? $this->string('status')->toString()
                : null,
            'trigger' => in_array($this->string('trigger')->toString(), $this->triggers(), true)
                ? $this->string('trigger')->toString()
                : null,
            'search' => $search !== '' ? $search : null,
            'active' => $this->boolean('active') ? '1' : null,
            'latest' => $this->boolean('latest') ? '1' : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    private function positiveInteger(string $key): ?int
    {
        $value = filter_var($this->query($key), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $value ?: null;
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return array_values(array_unique(array_merge(Build::ACTIVE_STATUSES, Build::TERMINAL_STATUSES)));
    }

    /** @return list<string> */
    private function triggers(): array
    {
        return [
            Build::TRIGGER_MANUAL,
            Build::TRIGGER_WEBHOOK,
            Build::TRIGGER_REDEPLOY,
            Build::TRIGGER_ROLLBACK,
            Build::TRIGGER_SCHEDULED,
            Build::TRIGGER_API,
            Build::TRIGGER_PROMOTION,
        ];
    }
}
