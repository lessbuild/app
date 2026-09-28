<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

final class AlertRoutingRequest extends FormRequest
{
    /**
     * Get the validation rules: up to five destinations, whether to alert on opening and on recovery, and the rule's
     * version.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'destinations' => ['sometimes', 'array', 'max:5'],
            'destinations.*' => ['required', 'integer', 'distinct', 'min:1'],
            'opened' => ['required', 'boolean'], 'recovered' => ['required', 'boolean'],
        ];
    }

    /**
     * Get the routing with IDs as integers and flags as booleans.
     *
     * @return array{version: int, destinations: list<int>, opened: bool, recovered: bool}
     */
    public function routing(): array
    {
        $data = $this->validated();

        return [
            'version' => (int) $data['version'],
            'destinations' => array_values(array_map('intval', is_array($data['destinations'] ?? null) ? $data['destinations'] : [])),
            'opened' => (bool) $data['opened'],
            'recovered' => (bool) $data['recovered'],
        ];
    }
}
