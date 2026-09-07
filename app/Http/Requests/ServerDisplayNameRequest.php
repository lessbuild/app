<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;

class ServerDisplayNameRequest extends FormRequest
{
    /**
     * Allow label changes only when a server is route-bound and the request user can update it.
     */
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

    /**
     * Collapse display-name whitespace and convert an empty label to null so the technical name is used.
     */
    protected function prepareForValidation(): void
    {
        $displayName = str((string) $this->input('display_name'))->squish()->toString();

        $this->merge([
            'display_name' => $displayName === '' ? null : $displayName,
        ]);
    }
}
