<?php

namespace App\Http\Requests;

use App\Models\Build;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepositoryIndexRequest extends FormRequest
{
    /** Authentication is supplied by the authenticated web route; repository queries scope through the current user. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate normalized repository-inventory filters without turning invalid values into user-facing errors.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'provider_id' => ['nullable', 'integer', 'min:1'],
            'website_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in($this->statuses())],
        ];
    }

    /**
     * Return the validated repository-inventory filter contract.
     *
     * @return array{search: ?string, provider_id: ?int, website_id: ?int, status: ?string}
     */
    public function filters(): array
    {
        /** @var array{search: ?string, provider_id: ?int, website_id: ?int, status: ?string} */
        return $this->validated();
    }

    /**
     * Validate normalized values without replacing query parameters used by pagination links.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $search = str($this->string('search')->toString())->trim()->limit(100, '')->toString();
        $status = $this->string('status')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'provider_id' => $this->positiveInteger('provider_id'),
            'website_id' => $this->positiveInteger('website_id'),
            'status' => in_array($status, $this->statuses(), true) ? $status : null,
        ];
    }

    private function positiveInteger(string $key): ?int
    {
        $value = filter_var($this->query($key), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $value ?: null;
    }

    /** @return list<string> The status values accepted by the inventory filter and displayed by its form. */
    public function statuses(): array
    {
        return [
            'none',
            ...array_values(array_unique(array_merge(Build::ACTIVE_STATUSES, Build::TERMINAL_STATUSES))),
        ];
    }
}
