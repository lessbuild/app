<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use Illuminate\Foundation\Http\FormRequest;

final class ConfirmPlatformTwoFactorRequest extends FormRequest
{
    protected $errorBag = 'twoFactor';

    public function authorize(): bool
    {
        return $this->user('platform') instanceof PlatformUser;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:20']];
    }

    public function code(): string
    {
        /** @var array{code:string} $validated */
        $validated = $this->validated();

        return $validated['code'];
    }
}
