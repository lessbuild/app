<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Models\Account;
use App\Models\AuditStream;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteAuditStream
{
    /**
     * Stop sending the account's audit entries to a stream.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  AuditStream  $stream
     * @return void
     */
    public function handle(User $actor, Account $account, AuditStream $stream): void
    {
        Gate::forUser($actor)->authorize('update', $account);
        $stream->delete();
    }
}
