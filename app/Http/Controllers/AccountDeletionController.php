<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\ServerCommandExecution;
use App\Services\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountDeletionController extends Controller
{
    /**
     * Validate email confirmation and applicable password/two-factor challenges before deleting the account.
     *
     * Shared memberships, remaining teammates, or active operations block deletion; success clears the session and redirects home.
     */
    public function __invoke(Request $request, TwoFactorAuthentication $twoFactor): RedirectResponse
    {
        $user = $request->user();
        $rules = [
            'confirmation' => ['required', Rule::in([$user->email])],
        ];
        if ($user->hasLocalPassword()) {
            $rules['current_password'] = ['required', 'current_password'];
        }
        if ($user->twoFactorEnabled()) {
            $rules['code'] = ['required', 'string', 'max:64'];
        }
        $data = $request->validateWithBag('deleteAccount', $rules);
        if ($user->twoFactorEnabled() && ! $twoFactor->verifyUser($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('The authentication or recovery code is invalid.')])->errorBag('deleteAccount');
        }

        $owned = $user->organizations()->where('organizations.owner_id', $user->id)->get();
        abort_if($user->organizations()->where('organizations.owner_id', '!=', $user->id)->exists(), 422, 'Leave every shared workspace before deleting your account.');
        abort_if($owned->contains(fn ($organization): bool => $organization->members()->whereKeyNot($user->id)->exists()), 422, 'Remove every teammate before deleting your account.');
        abort_if($this->hasActiveOperations($owned->pluck('id')->all()), 409, 'Wait for active deployments and commands to finish before deleting your account.');

        Auth::guard('web')->logout();
        DB::transaction(function () use ($owned, $user): void {
            foreach ($owned as $organization) {
                $organization->delete();
            }
            $user->tokens()->delete();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', __('Your BuildPusher account and workspaces were deleted.'));
    }

    /** @param list<int> $organizationIds */
    private function hasActiveOperations(array $organizationIds): bool
    {
        return Build::query()->whereIn('status', Build::ACTIVE_STATUSES)->whereHas('repository', fn ($query) => $query->whereIn('organization_id', $organizationIds))->exists()
            || ServerCommandExecution::query()->active()->whereHas('server', fn ($query) => $query->whereIn('organization_id', $organizationIds))->exists();
    }
}
