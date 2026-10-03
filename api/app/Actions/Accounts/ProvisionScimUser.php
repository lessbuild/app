<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Exceptions\ScimException;
use App\Models\Account;
use App\Models\ScimUser;
use App\Models\User;
use App\Services\Identity\ScimMemberships;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProvisionScimUser
{
    /**
     * Create a new ProvisionScimUser instance.
     *
     * @param  ScimMemberships  $memberships  Adds the membership.
     */
    public function __construct(private readonly ScimMemberships $memberships) {}

    /**
     * Add someone the identity provider assigned to the app: find them by email or create their user (signing in
     * through single sign-on, with no password), and make them a member unless they arrive deactivated.
     *
     * @param  Account  $account
     * @param  array{email?: string, name?: string, external_id?: string|null, active?: bool}  $input
     * @return ScimUser
     *
     * @throws ScimException
     */
    public function handle(Account $account, array $input): ScimUser
    {
        $email = $input['email'] ?? throw new ScimException(400, 'userName must be an email address.', 'invalidValue');
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user !== null && ScimUser::query()->where('account_id', $account->id)->where('user_id', $user->id)->exists()) {
            throw new ScimException(409, 'That user is already provisioned.', 'uniqueness');
        }
        $scimUser = DB::transaction(function () use ($account, $input, $email, $user): ScimUser {
            if ($user === null) {
                $user = new User;
                $user->forceFill(['name' => $input['name'] ?? Str::before($email, '@'), 'email' => $email, 'password' => null, 'email_verified_at' => now()])->save();
            }
            $scimUser = new ScimUser;
            $scimUser->forceFill(['account_id' => $account->id, 'user_id' => $user->id, 'external_id' => $input['external_id'] ?? null, 'active' => $input['active'] ?? true])->save();

            return $scimUser;
        });
        if ($scimUser->active) {
            $this->memberships->add($account, $scimUser->user);
        }

        return $scimUser;
    }
}
