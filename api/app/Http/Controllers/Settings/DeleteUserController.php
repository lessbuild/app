<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\DeleteUser;
use App\Exceptions\DeletionBlocked;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

final class DeleteUserController
{
    /**
     * Delete the person once they've typed their email exactly, then signs them out. Accounts they'd leave without an
     * owner block the deletion.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  DeleteUser  $delete
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, DeleteUser $delete): RedirectResponse
    {
        $request->validate(['confirm_email' => ['required', 'string']]);
        if (strcasecmp(trim($request->string('confirm_email')->toString()), $user->email) !== 0) {
            throw ValidationException::withMessages(['confirm_email' => __('Type your email address exactly as shown to confirm.')])->errorBag('deleteUser');
        }

        try {
            $delete->handle($user);
        } catch (DeletionBlocked $blocked) {
            throw ValidationException::withMessages(['confirm_email' => $blocked->getMessage()])->errorBag('deleteUser');
        }

        // Not Auth::logout(): it rotates the remember token by saving the user, which would insert the deleted row again.
        $guard = Auth::guard('web');
        $guard->forgetUser();
        Cookie::queue(Cookie::forget($guard->getRecallerName()));
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('status', __('Your account has been deleted.'));
    }
}
