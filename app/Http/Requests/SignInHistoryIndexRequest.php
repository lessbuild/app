<?php

namespace App\Http\Requests;

use App\Models\SignInEvent;
use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignInHistoryIndexRequest extends FormRequest
{
    /** Authentication is supplied by the authenticated account route; history is scoped to that account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate normalized sign-in history filters while preserving silent fallback for unsupported values.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'method' => ['nullable', 'string', Rule::in(SignInEvent::METHODS)],
            'date_from' => ['nullable', 'string'],
            'date_to' => ['nullable', 'string'],
        ];
    }

    /**
     * Return the validated sign-in history filter contract.
     *
     * @return array{method: ?string, date_from: ?string, date_to: ?string}
     */
    public function filters(): array
    {
        /** @var array{method: ?string, date_from: ?string, date_to: ?string} */
        return $this->validated();
    }

    /**
     * Normalize filters without replacing raw query parameters used by pagination and export links.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $method = $this->string('method')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $this->string('date_from')->toString(),
            $this->string('date_to')->toString(),
        );

        return [
            'method' => in_array($method, SignInEvent::METHODS, true) ? $method : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
