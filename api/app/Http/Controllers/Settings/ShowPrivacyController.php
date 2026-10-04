<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use App\Queries\Accounts\DepartureQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/settings/privacy`. */
final class ShowPrivacyController
{
    /**
     * Return the person's email address and what deleting them would do: accounts deleted with them, shared accounts
     * they'd leave, and accounts that block it until they hand over ownership.
     *
     * @param  User  $user
     * @param  DepartureQuery  $departure
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, DepartureQuery $departure): JsonResponse
    {
        $plan = $departure->handle($user);

        return response()->json([
            'email' => $user->email,
            'toDelete' => array_map(fn (Account $account): string => $account->name, $plan->toDelete),
            'toLeave' => array_map(fn (Membership $membership): string => $membership->account->name, $plan->toLeave),
            'blockedBy' => array_map(fn (Account $account): string => $account->name, $plan->blockedBy),
        ]);
    }
}
