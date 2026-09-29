<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Audit\DeleteAuditStream;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\AuditStream;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteAuditStreamController
{
    /**
     * Remove an audit stream and return to the audit log.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  int  $stream
     * @param  DeleteAuditStream  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, int $stream, DeleteAuditStream $delete): RedirectResponse
    {
        $delete->handle($user, $account, AuditStream::query()->where('account_id', $account->id)->findOrFail($stream));

        return to_route('account.audit-log')->with('status', __('Audit stream removed.'));
    }
}
