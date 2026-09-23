<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('workspace'));

        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['required', Rule::in(Workspace::ASSIGNABLE_ROLES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
