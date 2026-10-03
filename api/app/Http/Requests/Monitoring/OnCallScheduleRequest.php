<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

final class OnCallScheduleRequest extends FormRequest
{
    /**
     * Get the rules for an on-call schedule: its name, rotation, hand-over time and day, time zone, first day and
     * members in turn order.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone:all'],
            'rotation' => ['required', 'in:daily,weekly'],
            'handoff_time' => ['required', 'date_format:H:i'],
            'handoff_day' => ['nullable', 'integer', 'between:1,7'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'member_ids' => ['required', 'array', 'min:1', 'max:50'],
            'member_ids.*' => ['nullable', 'string'],
        ];
    }

    /**
     * Get the validated schedule as the action takes it.
     *
     * @return array{name: string, timezone: string, rotation: string, handoff_time: string, handoff_day: int|null, starts_on: string, member_ids: list<string>}
     */
    public function schedule(): array
    {
        return [
            'name' => $this->string('name')->toString(), 'timezone' => $this->string('timezone')->toString(),
            'rotation' => $this->string('rotation')->toString(), 'handoff_time' => $this->string('handoff_time')->toString(),
            'handoff_day' => $this->filled('handoff_day') ? $this->integer('handoff_day') : null, 'starts_on' => $this->string('starts_on')->toString(),
            'member_ids' => array_values(array_map('strval', array_filter((array) $this->input('member_ids', []), fn (mixed $id): bool => is_string($id) && $id !== ''))),
        ];
    }
}
