<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UsersController extends Controller
{
    public function index(): View
    {
        return view('scenes.users.index');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower((string) $request->input('email')),
        ]);

        $validated = $request->validateWithBag('profile', [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->id),
            ],
        ]);

        if ($request->user()->email !== $validated['email']) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->fill($validated)->save();

        return back()->with('profile_status', __('Profile updated.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $currentPasswordRules = $request->user()->hasLocalPassword()
            ? ['required', 'current_password']
            : ['nullable'];

        $validated = $request->validateWithBag('password', [
            'current_password' => $currentPasswordRules,
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),
        ]);

        $request->session()->regenerate();

        return back()->with('password_status', __('Password updated.'));
    }
}
