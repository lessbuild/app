<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;

class ServerDisplayNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server && $this->user()?->can('update', $server) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $displayName = str((string) $this->input('display_name'))->squish()->toString();

        $this->merge([
            'display_name' => $displayName === '' ? null : $displayName,
        ]);
    }
}
