<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\SetServiceAccess;
use App\Models\Account;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateMemberServicesController
{
    public function __construct(private readonly ServiceRegistry $services) {}

    public function __invoke(Request $request, #[CurrentUser] User $user, string $membership, SetServiceAccess $setAccess): RedirectResponse
    {
        $validated = $request->validate([
            'access' => ['required', Rule::in(['all', 'some'])],
            'services' => ['array'],
            'services.*' => ['string', Rule::in($this->services->keys())],
        ]);
        /** @var list<string> $services */
        $services = $validated['services'] ?? [];
        $updated = $setAccess->handle($user, $this->account($user)->memberships()->findOrFail($membership), $validated['access'] === 'all' ? null : $services);

        return to_route('account.members')->with('status', __('Service access saved for :name.', ['name' => $updated->user->name]));
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
