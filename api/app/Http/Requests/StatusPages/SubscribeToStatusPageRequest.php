<?php

declare(strict_types=1);

namespace App\Http\Requests\StatusPages;

use Illuminate\Foundation\Http\FormRequest;

/** Public: anyone may subscribe to a published page's updates. The address is confirmed by email before anything else is sent. */
final class SubscribeToStatusPageRequest extends FormRequest
{
    /**
     * Get the validation rules: a single email address.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:254']];
    }

    /**
     * Get the address, trimmed and lowercased so the same person can't subscribe twice with different casing.
     *
     * @return string
     */
    public function email(): string
    {
        return mb_strtolower(trim((string) $this->validated('email')));
    }
}
