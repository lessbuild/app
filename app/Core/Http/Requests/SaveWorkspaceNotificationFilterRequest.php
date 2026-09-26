<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveWorkspaceNotificationFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            ...FilterWorkspaceNotificationsRequest::filterRules(),
        ];
    }

    /** @return array{state:string,product:string,severity:string,project:string} */
    public function filterValues(): array
    {
        return [
            'state' => $this->validated('state') ?? 'all',
            'product' => $this->validated('product') ?? 'all',
            'severity' => $this->validated('severity') ?? 'all',
            'project' => $this->validated('project') ?? 'all',
        ];
    }
}
