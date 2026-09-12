<?php

namespace App\Http\Requests;

use App\Models\RecipeReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecipeReportHistoryRequest extends FormRequest
{
    /**
     * Authentication is supplied by the authenticated web route; the report history query is scoped to the current user.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate normalized reporter-history filters while retaining the existing silent fallback behavior.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['all', 'open', 'resolved'])],
            'availability' => ['required', Rule::in(['all', 'published', 'unpublished'])],
            'updates' => ['required', Rule::in(['all', 'unread', 'reviewed'])],
            'reason' => ['nullable', 'string', Rule::in(RecipeReport::REASONS)],
            'sort' => ['required', Rule::in(['newest', 'oldest', 'updated'])],
        ];
    }

    /**
     * Return the validated reporter-history filter contract.
     *
     * @return array{search: ?string, status: string, availability: string, updates: string, reason: ?string, sort: string}
     */
    public function filters(): array
    {
        /** @var array{search: ?string, status: string, availability: string, updates: string, reason: ?string, sort: string} */
        return $this->validated();
    }

    /**
     * Validate normalized values without replacing the raw query parameters used for pagination links.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $search = str($this->string('search')->toString())->trim()->limit(100, '')->toString();
        $status = $this->string('status')->toString();
        $availability = $this->string('availability')->toString();
        $updates = $this->string('updates')->toString();
        $reason = $this->string('reason')->toString();
        $sort = $this->string('sort')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'status' => in_array($status, ['all', 'open', 'resolved'], true) ? $status : 'all',
            'availability' => in_array($availability, ['all', 'published', 'unpublished'], true) ? $availability : 'all',
            'updates' => in_array($updates, ['all', 'unread', 'reviewed'], true) ? $updates : 'all',
            'reason' => in_array($reason, RecipeReport::REASONS, true) ? $reason : null,
            'sort' => in_array($sort, ['newest', 'oldest', 'updated'], true) ? $sort : 'newest',
        ];
    }
}
