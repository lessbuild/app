<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

final class CollectEventsRequest extends FormRequest
{
    /**
     * Refuse bodies over 32 KiB before anything is parsed.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        abort_if(strlen($this->getContent()) > 32768, 413, 'Analytics payload is too large.');
    }

    /**
     * Allow the request; the collection endpoint is public, and the site and origin are checked by the controller.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules: one to twenty events with their IDs, types, paths and optional attribution, device and
     * session fields, each cut to its column's length. The client's timestamp is accepted but ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*.id' => ['required', 'uuid'],
            'events.*.type' => ['required', 'string', 'in:pageview,event,vitals,engagement'],
            // The client timestamp is accepted only for schema compatibility;
            // collection always records the server receipt time.
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.path' => ['required', 'string', 'max:2048'],
            'events.*.referrer_host' => ['nullable', 'string', 'max:255'],
            'events.*.utm_source' => ['nullable', 'string', 'max:100'],
            'events.*.utm_medium' => ['nullable', 'string', 'max:100'],
            'events.*.utm_campaign' => ['nullable', 'string', 'max:150'],
            'events.*.utm_term' => ['nullable', 'string', 'max:150'],
            'events.*.utm_content' => ['nullable', 'string', 'max:150'],
            'events.*.screen' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'events.*.browser_version' => ['nullable', 'string', 'max:32'],
            'events.*.os_version' => ['nullable', 'string', 'max:32'],
            'events.*.device' => ['nullable', 'string', 'max:32'],
            'events.*.browser' => ['nullable', 'string', 'max:64'],
            'events.*.os' => ['nullable', 'string', 'max:64'],
            'events.*.visitor' => ['nullable', 'string', 'max:64'],
            'events.*.session' => ['nullable', 'string', 'max:64'],
            'events.*.returning' => ['nullable', 'string', 'max:64'],
            'events.*.hash' => ['nullable', 'boolean'],
            'events.*.properties' => ['nullable', 'array', 'max:24'],
        ];
    }
}
