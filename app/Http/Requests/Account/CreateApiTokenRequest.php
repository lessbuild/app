<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\ApiScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateApiTokenRequest extends FormRequest
{
    public const EXPIRY_CHOICES = ['30', '90', '365', 'never'];

    /**
     * A name, at least one scope, and one of the offered expiry choices.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', Rule::enum(ApiScope::class)],
            'expires' => ['required', Rule::in(self::EXPIRY_CHOICES)],
        ];
    }

    /**
     * The token to create; "never" becomes no expiry.
     */
    public function toData(): CreateApiTokenData
    {
        /** @var list<string> $scopes */
        $scopes = $this->validated('scopes');
        $expires = $this->string('expires')->toString();

        return new CreateApiTokenData(
            name: $this->string('name')->trim()->toString(),
            scopes: array_map(ApiScope::from(...), $scopes),
            expiresInDays: $expires === 'never' ? null : (int) $expires,
        );
    }
}
