<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\RegisterUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Services\AccessInvitation;
use App\Services\RegistrationAccess;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function store(RegisterUserRequest $request, RegisterUserAction $register): RedirectResponse
    {
        if (! $request->registrationIsAvailable()) {
            return $this->closedResponse();
        }

        $user = $register->handle($request->registrationData());

        if (! $user) {
            return $this->closedResponse();
        }

        $request->session()->forget('access_invitation_token');

        Auth::login($user);

        event(new Registered($user));

        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }

    /**
     * Redirect to login with the shared registration-closed error for unavailable registration paths.
     */
    private function closedResponse(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'registration' => __('Registration is closed. Ask the owner to enable new account creation.'),
        ]);
    }
}
