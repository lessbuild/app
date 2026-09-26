<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCustomerStatusSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:254']];
    }

    public function email(): string
    {
        return strtolower((string) $this->validated('email'));
    }
}
