<?php

namespace App\Modules\Monitor\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'workspace_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:monitor.users,email'],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::defaults()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
