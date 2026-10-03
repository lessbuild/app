<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\SetServiceAccess;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateMemberServicesController
{
    /**
     * Create a new UpdateMemberServicesController instance.
     *
     * Saves a member's service access.
     *
     * @param  ServiceRegistry  $services  The service keys the form may send.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Set which services a member may use: all of them, or the ones ticked.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $membership
     * @param  SetServiceAccess  $setAccess
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, string $membership, SetServiceAccess $setAccess): JsonResponse
    {
        $validated = $request->validate([
            'access' => ['required', Rule::in(['all', 'some'])],
            'services' => ['array'],
            'services.*' => ['string', Rule::in($this->services->keys())],
        ]);
        /** @var list<string> $services */
        $services = $validated['services'] ?? [];
        $updated = $setAccess->handle($user, $account->memberships()->findOrFail($membership), $validated['access'] === 'all' ? null : $services);

        return response()->json(['redirect' => route('account.members', [], false), 'message' => __('Service access saved for :name.', ['name' => $updated->user->name])]);
    }
}
