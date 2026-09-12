<?php

namespace App\Http\Requests;

use App\Enums\NotificationBulkOperation;
use Illuminate\Foundation\Http\FormRequest;

class BulkNotificationRequest extends FormRequest
{
    /** Authentication is supplied by the authenticated web route; the action scopes IDs to this account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the bounded, distinct notification selection and its supported operation.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:read,unread,delete'],
            'notifications' => ['required', 'array', 'min:1', 'max:25'],
            'notifications.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    /** Return the validated bulk operation without exposing the raw request to the action. */
    public function operation(): NotificationBulkOperation
    {
        return NotificationBulkOperation::from((string) $this->validated('action'));
    }

    /** @return list<string> The validated notification UUIDs selected by the account. */
    public function notificationIds(): array
    {
        /** @var list<string> */
        return $this->validated('notifications');
    }
}
