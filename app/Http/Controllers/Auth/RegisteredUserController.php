<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RegistrationAccess;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(RegistrationAccess $registration): View|RedirectResponse
    {
        if (! $registration->allowsNewUser()) {
            return $this->closedResponse();
        }

        return view('scenes.auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     *
     * @throws ValidationException
     */
    public function store(Request $request, RegistrationAccess $registration): RedirectResponse
    {
        if (! $registration->allowsNewUser()) {
            return $this->closedResponse();
        }

        $request->merge([
            'email' => Str::lower((string) $request->input('email')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $registration->synchronized(function () use ($registration, $validated): ?User {
            if (! $registration->allowsNewUser()) {
                return null;
            }

            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'password_set_at' => now(),
            ]);
        });

        if (! $user) {
            return $this->closedResponse();
        }

        Auth::login($user);

        event(new Registered($user));

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function closedResponse(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'registration' => __('Registration is closed. Ask the owner to enable new account creation.'),
        ]);
    }
}
