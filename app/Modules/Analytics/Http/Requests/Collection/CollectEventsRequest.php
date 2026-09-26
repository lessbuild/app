<?php

namespace App\Modules\Analytics\Http\Requests\Collection;

use App\Modules\Analytics\Services\AnalyticsCollectionLimits;
use Illuminate\Foundation\Http\FormRequest;

class CollectEventsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        abort_if(strlen($this->getContent()) > AnalyticsCollectionLimits::MAX_REQUEST_BYTES, 413, 'Analytics payload is too large.');
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.AnalyticsCollectionLimits::MAX_EVENTS_PER_BATCH],
            'events.*.id' => ['required', 'uuid'],
            'events.*.type' => ['required', 'string', 'in:pageview,event'],
            // The client timestamp is accepted only for schema compatibility;
            // collection always records the server receipt time.
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.path' => ['required', 'string', 'max:2048'],
            'events.*.referrer_host' => ['nullable', 'string', 'max:255'],
            'events.*.utm_source' => ['nullable', 'string', 'max:100'],
            'events.*.utm_medium' => ['nullable', 'string', 'max:100'],
            'events.*.utm_campaign' => ['nullable', 'string', 'max:150'],
            'events.*.device' => ['nullable', 'string', 'max:32'],
            'events.*.browser' => ['nullable', 'string', 'max:64'],
            'events.*.os' => ['nullable', 'string', 'max:64'],
            'events.*.visitor' => ['nullable', 'string', 'max:64'],
            'events.*.session' => ['nullable', 'string', 'max:64'],
            'events.*.properties' => ['nullable', 'array', 'max:8'],
        ];
    }
}
