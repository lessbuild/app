<?php

namespace App\Http\Requests;

use App\Models\RecipeReport;
use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecipeReportInboxRequest extends FormRequest
{
    /**
     * Authentication is supplied by the authenticated web route; the report query is scoped to the current user.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the normalized contributor-inbox filters while retaining the existing silent fallback behavior.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['all', 'unresolved', 'resolved'])],
            'reason' => ['nullable', 'string', Rule::in(RecipeReport::REASONS)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'age' => ['nullable', Rule::in(['24h', '7d', '30d'])],
            'sort' => ['required', Rule::in(['newest', 'oldest', 'updated', 'priority'])],
            'recipe' => ['nullable', 'integer', 'min:1'],
            'report' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Return the validated contributor-inbox filter contract.
     *
     * @return array{search: ?string, status: string, reason: ?string, date_from: ?string, date_to: ?string, age: ?string, sort: string, recipe: ?int, report: ?int}
     */
    public function filters(): array
    {
        /** @var array{search: ?string, status: string, reason: ?string, date_from: ?string, date_to: ?string, age: ?string, sort: string, recipe: ?int, report: ?int} */
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
        $reason = $this->string('reason')->toString();
        $age = $this->string('age')->toString();
        $sort = $this->string('sort')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $this->string('date_from')->toString(),
            $this->string('date_to')->toString(),
        );

        return [
            'search' => $search !== '' ? $search : null,
            'status' => in_array($status, ['all', 'unresolved', 'resolved'], true) ? $status : 'unresolved',
            'reason' => in_array($reason, RecipeReport::REASONS, true) ? $reason : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'age' => in_array($age, ['24h', '7d', '30d'], true) ? $age : null,
            'sort' => in_array($sort, ['newest', 'oldest', 'updated', 'priority'], true) ? $sort : 'newest',
            'recipe' => $this->positiveInteger('recipe'),
            'report' => $this->positiveInteger('report'),
        ];
    }

    private function positiveInteger(string $key): ?int
    {
        $value = filter_var($this->query($key), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $value ?: null;
    }
}
