<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Models\SignInEvent;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** Customer lookup for support: find people and accounts, and see an account or person whole, without secrets. */
final class CustomersQuery
{
    /**
     * Find up to 25 people (by email, name or ID) and 25 accounts (by name, ID or Stripe customer ID) matching a term
     * of at least two characters.
     *
     * @param  string  $term
     * @return array{users: Collection<int, User>, accounts: Collection<int, Account>}
     */
    public function search(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return ['users' => new Collection, 'accounts' => new Collection];
        }
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        return [
            'users' => User::query()->where(fn ($query) => $query->whereRaw('LOWER(email) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like])->orWhere('id', $term))
                ->orderBy('email')->limit(25)->get(),
            'accounts' => Account::query()->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$like])->orWhere('id', $term)
                ->orWhereIn('id', BillingAccount::query()->where('stripe_customer_id', $term)->select('account_id')))->withCount(['memberships', 'projects'])->orderBy('name')->limit(25)->get(),
        ];
    }

    /**
     * Get an account with its members, projects and their services, billing and chosen tiers and add-ons, this month's usage and the latest 25
     * audit entries.
     *
     * @param  string  $id
     * @return array{account: Account, billing: BillingAccount|null, selections: Collection<int, BillingSelection>, usage: Collection<int, UsageRecord>, audit: Collection<int, AuditEntry>}
     */
    public function account(string $id): array
    {
        $account = Account::query()->with(['memberships.user', 'projects.enabledServices'])->findOrFail($id);

        return [
            'account' => $account,
            'billing' => BillingAccount::query()->find($account->id),
            'selections' => BillingSelection::query()->where('account_id', $account->id)->orderBy('service')->get(),
            'usage' => UsageRecord::query()->where('account_id', $account->id)->where('period_start', '>=', now()->startOfMonth())->orderBy('meter')->get(),
            'audit' => AuditEntry::query()->where('account_id', $account->id)->latest('created_at')->limit(25)->get(),
        ];
    }

    /**
     * Get a person with their memberships, passkey and connected-provider counts, and the latest 25 sign-ins.
     *
     * @param  string  $id
     * @return array{user: User, passkeys: int, signIns: Collection<int, SignInEvent>}
     */
    public function user(string $id): array
    {
        $user = User::query()->with(['memberships.account', 'socialIdentities'])->findOrFail($id);

        return [
            'user' => $user,
            'passkeys' => $user->passkeys()->count(),
            'signIns' => SignInEvent::query()->where('user_id', $user->id)->latest('created_at')->limit(25)->get(),
        ];
    }
}
