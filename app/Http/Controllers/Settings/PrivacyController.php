<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Accounts\Queries\DepartureQuery;
use App\Domain\Identity\Actions\DeleteUser;
use App\Domain\Identity\Exceptions\DeletionBlocked;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Queries\PersonalDataExportQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivacyController
{
    public function index(#[CurrentUser] User $user, DepartureQuery $departure): View
    {
        return view('settings.privacy', ['user' => $user, 'departure' => $departure->handle($user)]);
    }

    public function export(#[CurrentUser] User $user, PersonalDataExportQuery $query): StreamedResponse
    {
        $json = json_encode($query->handle($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->streamDownload(fn () => print ($json), 'personal-data-'.now()->format('Y-m-d').'.json', [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, #[CurrentUser] User $user, DeleteUser $delete): RedirectResponse
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
