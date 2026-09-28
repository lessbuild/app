<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

final class AlertEscalationsRequest extends FormRequest
{
    /**
     * Get the validation rules: up to ten steps, each a destination and a delay in minutes (up to a week), and the
     * rule's version.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'escalations' => ['sometimes', 'array', 'max:10'],
            'escalations.*' => ['required', 'array'],
            'escalations.*.destination_id' => ['nullable', 'integer', 'min:1'],
            'escalations.*.delay_minutes' => ['nullable', 'integer', 'between:1,10080'],
        ];
    }

    /**
     * Get the steps with IDs and delays as integers; blank fields become null.
     *
     * @return array{version: int, escalations: list<array{destination_id: int|null, delay_minutes: int|null}>}
     */
    public function steps(): array
    {
        $data = $this->validated();
        $steps = [];
        foreach (is_array($data['escalations'] ?? null) ? $data['escalations'] : [] as $step) {
            $steps[] = [
                'destination_id' => is_numeric($step['destination_id'] ?? null) ? (int) $step['destination_id'] : null,
                'delay_minutes' => is_numeric($step['delay_minutes'] ?? null) ? (int) $step['delay_minutes'] : null,
            ];
        }

        return ['version' => (int) $data['version'], 'escalations' => $steps];
    }
}
