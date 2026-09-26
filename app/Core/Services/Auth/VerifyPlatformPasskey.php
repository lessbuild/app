<?php

namespace App\Core\Services\Auth;

use App\Core\Models\Passkey;
use App\Core\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/** Verifies passkeys and advances their signature counters within Core's transaction. */
final class VerifyPlatformPasskey
{
    public function handle(
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
    ): Passkey {
        if (! $credential->response instanceof AuthenticatorAssertionResponse) {
            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }

        return DB::connection('core')->transaction(function () use ($credential, $options): Passkey {
            $credentialId = Base64UrlSafe::encodeUnpadded($credential->rawId);
            $passkey = Passkey::query()->where('credential_id', $credentialId)->lockForUpdate()->first();

            if (! $passkey instanceof Passkey) {
                throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
            }

            $user = $passkey->user;
            if (! $user instanceof PlatformUser || $user->status !== 'active') {
                throw InvalidPasskeyException::make('Unable to sign in with this account.');
            }

            $source = WebAuthn::fromJson(
                json_encode($passkey->credential, JSON_THROW_ON_ERROR),
                CredentialRecord::class,
            );
            $updatedCredential = WebAuthn::assertionValidator()->check(
                credentialRecord: $source,
                authenticatorAssertionResponse: $credential->response,
                publicKeyCredentialRequestOptions: $options,
                host: config('passkeys.relying_party_id'),
                userHandle: $source->userHandle,
            );

            $passkey->forceFill([
                'credential' => json_decode(WebAuthn::toJson($updatedCredential), true, flags: JSON_THROW_ON_ERROR),
                'last_used_at' => now(),
            ])->save();

            PasskeyVerified::dispatch($user, $passkey);

            return $passkey;
        });
    }
}
