<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Domain\Accounts\Data\InviteMemberData;
use App\Domain\Accounts\Enums\AccountRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteMemberRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(AccountRole::class)],
        ];
    }

    public function toData(): InviteMemberData
    {
        return new InviteMemberData($this->string('email')->toString(), $this->enum('role', AccountRole::class) ?? AccountRole::Member);
    }
}
