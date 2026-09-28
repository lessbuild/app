<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Data\Accounts\InviteMemberData;
use App\Enums\AccountRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteMemberRequest extends FormRequest
{
    /**
     * The invitee's email and a role.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(AccountRole::class)],
        ];
    }

    /**
     * The invitation to send.
     *
     * @return InviteMemberData
     */
    public function toData(): InviteMemberData
    {
        return new InviteMemberData($this->string('email')->toString(), $this->enum('role', AccountRole::class) ?? AccountRole::Member);
    }
}
