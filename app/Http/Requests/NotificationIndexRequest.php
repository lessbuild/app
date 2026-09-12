<?php

namespace App\Http\Requests;

use App\Notifications\NotificationInbox;
use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationIndexRequest extends FormRequest
{
    /** Authentication is supplied by the authenticated web route; inbox results are scoped to the current account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the normalized notification inbox filters while preserving silent fallback for unsupported values.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::in(NotificationInbox::CATEGORIES)],
            'status' => ['nullable', 'string', Rule::in(NotificationInbox::STATUSES)],
            'state' => ['nullable', 'string', Rule::in(['unread', 'read'])],
            'date_from' => ['nullable', 'string'],
            'date_to' => ['nullable', 'string'],
        ];
    }

    /**
     * Return the validated notification inbox filter contract.
     *
     * @return array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string}
     */
    public function filters(): array
    {
        /** @var array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string} */
        return $this->validated();
    }

    /**
     * Validate normalized values without changing the raw query parameters used by pagination links.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $search = str($this->string('search')->toString())->trim()->limit(100, '')->toString();
        $category = $this->string('category')->toString();
        $status = $this->string('status')->toString();
        $state = $this->string('state')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $this->string('date_from')->toString(),
            $this->string('date_to')->toString(),
        );

        return [
            'search' => $search !== '' ? $search : null,
            'category' => in_array($category, NotificationInbox::CATEGORIES, true) ? $category : null,
            'status' => in_array($status, NotificationInbox::STATUSES, true) ? $status : null,
            'state' => in_array($state, ['unread', 'read'], true) ? $state : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
