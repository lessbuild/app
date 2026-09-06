<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccessInvitation;
use App\Services\PersonalOrganization;
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
    public function create(Request $request, RegistrationAccess $registration, AccessInvitation $invitations): View|RedirectResponse
    {
        $queryToken = (string) $request->query('invite');
        if ($queryToken !== '') {
            if (! $invitations->find($queryToken)) {
                return $this->closedResponse();
            }
            $request->session()->put('access_invitation_token', $queryToken);

            return redirect()->route('register');
        }

        $invitationToken = (string) $request->session()->get('access_invitation_token', '');
        $invitation = $invitations->find($invitationToken);
        if (! $invitation) {
            $request->session()->forget('access_invitation_token');
        }
        if (! $registration->allowsNewUser() && ! $invitation) {
            return $this->closedResponse();
        }

        return view('scenes.auth.register', ['invitation' => $invitation, 'invitationToken' => $invitation ? $invitationToken : null]);
    }

    /**
     * Handle an incoming registration request.
     *
     *
     * @throws ValidationException
     */
    public function store(Request $request, RegistrationAccess $registration, PersonalOrganization $organizations, AccessInvitation $invitations): RedirectResponse
    {
        $invitationToken = (string) $request->input('invite');
        $invitation = $invitations->find($invitationToken);
        if (! $registration->allowsNewUser() && ! $invitation) {
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

        if ($invitation && ! hash_equals($invitation->email, $validated['email'])) {
            throw ValidationException::withMessages(['email' => __('Use the email address that received this invitation.')]);
        }

        $createUser = fn (): User => User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),
        ]);

        $user = $invitation
            ? $invitations->consume($invitationToken, fn ($lockedInvitation) => hash_equals($lockedInvitation->email, $validated['email']) ? $createUser() : null)
            : $registration->synchronized(function () use ($registration, $createUser): ?User {
                return $registration->allowsNewUser() ? $createUser() : null;
            });

        if (! $user) {
            return $this->closedResponse();
        }

        $request->session()->forget('access_invitation_token');

        $organizations->ensure($user);

        Auth::login($user);

        event(new Registered($user));

        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }

    private function closedResponse(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'registration' => __('Registration is closed. Ask the owner to enable new account creation.'),
        ]);
    }
}
