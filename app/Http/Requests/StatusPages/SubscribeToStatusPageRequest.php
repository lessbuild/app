<?php

declare(strict_types=1);

namespace App\Http\Requests\StatusPages;

use Illuminate\Foundation\Http\FormRequest;

/** Public: anyone may subscribe to a published page's updates. The address is confirmed by email before anything else is sent. */
final class SubscribeToStatusPageRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:254']];
    }

    public function email(): string
    {
        return mb_strtolower(trim((string) $this->validated('email')));
    }
}
