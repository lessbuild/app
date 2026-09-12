<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivityIndexRequest extends FormRequest
{
    /** Authentication is supplied by the authenticated web route; activity is scoped to the current account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate normalized activity filters while retaining silent fallback for unsupported query values.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::in(Event::CATEGORIES)],
            'date_from' => ['nullable', 'string'],
            'date_to' => ['nullable', 'string'],
        ];
    }

    /**
     * Return the validated activity filter contract.
     *
     * @return array{search: ?string, category: ?string, date_from: ?string, date_to: ?string}
     */
    public function filters(): array
    {
        /** @var array{search: ?string, category: ?string, date_from: ?string, date_to: ?string} */
        return $this->validated();
    }

    /**
     * Normalize filters without replacing raw query parameters used by pagination and export links.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $search = str($this->string('search')->toString())->trim()->limit(100, '')->toString();
        $category = $this->string('category')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $this->string('date_from')->toString(),
            $this->string('date_to')->toString(),
        );

        return [
            'search' => $search !== '' ? $search : null,
            'category' => in_array($category, Event::CATEGORIES, true) ? $category : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
