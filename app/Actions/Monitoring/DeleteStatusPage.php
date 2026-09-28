<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteStatusPage
{
    /**
     * Deletes a status page and its subscriptions.
     *
     * @param  RecordAuditEntry  $audit  Records the deletion.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Delete a status page with its updates and subscribers. Its public address stops working at once; monitors aren't affected.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  StatusPage  $page
     * @return void
     */
    public function handle(Account $account, User $actor, StatusPage $page): void
    {
        DB::transaction(function () use ($account, $actor, $page): void {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('delete', $page);
            $page = StatusPage::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($page->id);
            $page->delete();
            $this->audit->handle(AuditAction::StatusPageDeleted, $actor, $account->id, ['page' => $page->name, 'slug' => $page->slug]);
        }, attempts: 3);
    }
}
