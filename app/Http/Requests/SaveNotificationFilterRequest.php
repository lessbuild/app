<?php

namespace App\Http\Requests;

class SaveNotificationFilterRequest extends NotificationIndexRequest
{
    /**
     * Validate the saved preset name in addition to its normalized inbox criteria.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:40'],
        ];
    }

    /** Return the validated saved-preset display name. */
    public function filterName(): string
    {
        return (string) $this->validated('name');
    }

    /**
     * Include the saved-preset name while retaining the shared filter normalization.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'name' => $this->input('name'),
        ];
    }
}
