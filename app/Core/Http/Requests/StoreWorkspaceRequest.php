<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('platform');

        return $user instanceof PlatformUser
            && $user->status === 'active'
            && $user->hasVerifiedEmail();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
