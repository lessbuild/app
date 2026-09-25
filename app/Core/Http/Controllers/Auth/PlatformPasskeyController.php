<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Http\Requests\BeginPlatformPasskeyRegistrationRequest;
use App\Core\Http\Requests\DeletePlatformPasskeyRequest;
use App\Core\Http\Requests\PlatformPasskeyActionRequest;
use App\Core\Http\Requests\PlatformPasskeyLoginRequest;
use App\Core\Http\Requests\PlatformPasskeyRegistrationRequest;
use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\PlatformAuthenticationSessions;
use App\Core\Services\Auth\PlatformRedirectTarget;
use App\Core\Services\Auth\PlatformSsoHandoff;
use App\Core\Services\Auth\VerifyPlatformPasskey;
use App\Core\Services\Auth\VerifyPlatformTwoFactorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Symfony\Component\HttpFoundation\Response;

final class PlatformPasskeyController
{
    public function loginOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();
        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function login(
        PlatformPasskeyLoginRequest $request,
        VerifyPlatformPasskey $verify,
        PlatformRedirectTarget $redirects,
        PlatformAuthenticationSessions $sessions,
        PlatformSsoHandoff $handoff,
    ): Response {
        $passkey = $verify->handle($request->credential(), $request->verificationOptions());
        $user = $passkey->user;
        abort_unless($user instanceof PlatformUser && $user->status === 'active', 422);

        $guard = Auth::guard('platform');
        $previousUser = $guard->user();
        if ($previousUser instanceof PlatformUser) {
            $sessions->revoke($previousUser, $request);
            $guard->logout();
        }

        $returnTo = $redirects->resolve($request->validated('return_to'), $request);
        if ($user->twoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put([
                'platform.auth.two_factor_user_id' => $user->getKey(),
                'platform.auth.remember' => $request->remember(),
                'platform.auth.return_to' => $returnTo,
            ]);

            return to_route('platform.two-factor.create');
        }

        $guard->login($user, $request->remember());
        $request->session()->regenerate();
        $sessions->begin($user, $request, $request->remember());

        return $handoff->respond($request, $user, $returnTo ?? route('core.home'));
    }

    public function registrationOptions(
        BeginPlatformPasskeyRegistrationRequest $request,
        GenerateRegistrationOptions $generate,
        VerifyPlatformTwoFactorCode $verifyCode,
    ): JsonResponse {
        $user = $this->user($request);
        $this->verifySecondFactor($request, $user, $verifyCode);

        $options = $generate($user);
        $request->session()->put([
            'passkey.registration_options' => WebAuthn::toJson($options),
            'platform.auth.passkey_registration_user_id' => (string) $user->getKey(),
            'platform.auth.passkey_registration_confirmed_at' => now()->getTimestamp(),
            'platform.auth.passkey_registration_name' => $request->validated('name'),
        ]);

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(
        PlatformPasskeyRegistrationRequest $request,
        StorePasskey $storePasskey,
    ): JsonResponse {
        $user = $this->user($request);
        $name = $request->session()->get('platform.auth.passkey_registration_name');
        abort_unless(is_string($name) && $name !== '', 419);

        $passkey = DB::connection('core')->transaction(function () use ($user, $name, $request, $storePasskey): Passkey {
            $user = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($user->status === 'active', 403);

            return $storePasskey($user, $name, $request->credential(), $request->registrationOptions());
        });

        $request->session()->forget([
            'platform.auth.passkey_registration_user_id',
            'platform.auth.passkey_registration_confirmed_at',
            'platform.auth.passkey_registration_name',
        ]);

        return response()->json([
            'status' => 'passkey-registered',
            'id' => (string) $passkey->getKey(),
            'name' => $passkey->name,
        ]);
    }

    public function destroy(
        DeletePlatformPasskeyRequest $request,
        string $passkeyId,
        DeletePasskey $deletePasskey,
        VerifyPlatformTwoFactorCode $verifyCode,
    ): RedirectResponse {
        $user = $this->user($request);
        $this->verifySecondFactor($request, $user, $verifyCode);
        DB::connection('core')->transaction(function () use ($user, $passkeyId, $deletePasskey): void {
            $lockedUser = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($lockedUser->status === 'active', 403);
            $passkeyModel = $lockedUser->passkeys()->lockForUpdate()->whereKey($passkeyId)->firstOrFail();
            $remaining = $lockedUser->passkeys()->where('id', '!=', $passkeyModel->getKey())->count();
            $hasOtherSignInMethod = $lockedUser->hasPassword()
                || $lockedUser->identities()->where('status', 'active')->exists();

            if ($remaining === 0 && ! $hasOtherSignInMethod) {
                throw ValidationException::withMessages([
                    'passkey_name' => __('Keep at least one passkey or another sign-in method before removing this one.'),
                ])->errorBag('passkeys');
            }

            $deletePasskey($lockedUser, $passkeyModel);
        });

        return $this->privateRedirect(back()->with('security_status', __('The passkey was removed from your account.')));
    }

    private function verifySecondFactor(
        PlatformPasskeyActionRequest $request,
        PlatformUser $user,
        VerifyPlatformTwoFactorCode $verifyCode,
    ): void {
        if ($user->twoFactorEnabled() && ! $verifyCode->handle($user, (string) $request->validated('code'))) {
            throw ValidationException::withMessages([
                'code' => __('The authentication or recovery code is invalid.'),
            ])->errorBag('passkeys');
        }
    }

    private function user(Request $request): PlatformUser
    {
        $user = $request->user('platform') ?? Auth::guard('platform')->user();
        abort_unless($user instanceof PlatformUser && $user->status === 'active', 401);

        return $user;
    }

    private function privateRedirect(RedirectResponse $response): RedirectResponse
    {
        return $response->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
