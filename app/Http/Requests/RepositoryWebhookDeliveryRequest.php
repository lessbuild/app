<?php

namespace App\Http\Requests;

use App\Models\RepositoryWebhookDelivery;
use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepositoryWebhookDeliveryRequest extends FormRequest
{
    /** Authentication and repository visibility are enforced by the route controller. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate normalized webhook-delivery history filters without changing their silent fallback behavior.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'delivery_status' => ['nullable', Rule::in(RepositoryWebhookDelivery::STATUSES)],
            'delivery_date_from' => ['nullable', 'date_format:Y-m-d'],
            'delivery_date_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Return the validated repository webhook-delivery filter contract.
     *
     * @return array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string}
     */
    public function filters(): array
    {
        /** @var array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string} */
        return $this->validated();
    }

    /**
     * Validate normalized values without replacing query parameters used by pagination links.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $status = $this->string('delivery_status')->toString();
        [$dateFrom, $dateTo] = DateRange::normalize(
            $this->string('delivery_date_from')->toString(),
            $this->string('delivery_date_to')->toString(),
        );

        return [
            'delivery_status' => in_array($status, RepositoryWebhookDelivery::STATUSES, true) ? $status : null,
            'delivery_date_from' => $dateFrom,
            'delivery_date_to' => $dateTo,
        ];
    }
}
