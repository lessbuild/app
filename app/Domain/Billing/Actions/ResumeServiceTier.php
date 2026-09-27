<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Billing\Enums\SelectionKind;
use App\Domain\Billing\Models\BillingSelection;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

final class ResumeServiceTier
{
    /** Undo a downgrade that hasn't happened yet. Returns false when nothing was scheduled. */
    public function handle(User $actor, Account $account, string $service): bool
    {
        Gate::forUser($actor)->authorize('manageBilling', $account);

        return BillingSelection::query()
            ->where('account_id', $account->id)->where('service', $service)->where('kind', SelectionKind::Tier)
            ->whereNotNull('ends_at')->where('ends_at', '>', now())
            ->update(['ends_at' => null]) > 0;
    }
}
