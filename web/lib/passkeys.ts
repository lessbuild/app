// WebAuthn for passkeys (laravel/passkeys' JSON endpoints under /api/app/auth): sign in, confirm it's you, and add one.

import { ensureCsrf, send } from './client';

type Json = Record<string, unknown>;

function decodeBase64Url(value: string): Uint8Array<ArrayBuffer> {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = window.atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='));
    return Uint8Array.from(binary, (character) => character.charCodeAt(0));
}

function encodeBase64Url(value: ArrayBuffer): string {
    const bytes = new Uint8Array(value);
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
    }
    return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

/** Turn the server's options (base64url strings) into what the browser's WebAuthn API takes. */
function decodeOptions(options: Json): Json {
    const publicKey: Json = { ...options };
    if (typeof publicKey.challenge === 'string') {
        publicKey.challenge = decodeBase64Url(publicKey.challenge);
    }
    const user = publicKey.user as Json | undefined;
    if (user && typeof user.id === 'string') {
        publicKey.user = { ...user, id: decodeBase64Url(user.id) };
    }
    for (const key of ['allowCredentials', 'excludeCredentials']) {
        if (Array.isArray(publicKey[key])) {
            publicKey[key] = (publicKey[key] as Json[]).map((credential) => ({ ...credential, id: decodeBase64Url(String(credential.id)) }));
        }
    }
    return publicKey;
}

/** Turn the browser's credential into JSON for the server. */
function encodeCredential(credential: PublicKeyCredential): Json {
    const response = credential.response as AuthenticatorAttestationResponse & AuthenticatorAssertionResponse;
    const payload: Json & { response: Json } = {
        id: credential.id,
        rawId: encodeBase64Url(credential.rawId),
        type: credential.type,
        response: { clientDataJSON: encodeBase64Url(response.clientDataJSON) },
        clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
    };
    if (response.attestationObject) payload.response.attestationObject = encodeBase64Url(response.attestationObject);
    if (response.authenticatorData) payload.response.authenticatorData = encodeBase64Url(response.authenticatorData);
    if (response.signature) payload.response.signature = encodeBase64Url(response.signature);
    if (response.userHandle) payload.response.userHandle = encodeBase64Url(response.userHandle);
    if (typeof response.getTransports === 'function') payload.response.transports = response.getTransports();
    if (credential.authenticatorAttachment) payload.authenticatorAttachment = credential.authenticatorAttachment;
    return payload;
}

/** Whether this browser can use passkeys. */
export function passkeysSupported(): boolean {
    return typeof window !== 'undefined' && typeof window.PublicKeyCredential !== 'undefined' && !!navigator.credentials?.get;
}

/** Ask the device for a passkey assertion against options from one endpoint and post it to another. */
async function assert(optionsPath: string, postPath: string, extra: Json = {}): Promise<Json> {
    await ensureCsrf();
    const { options } = await send<{ options: Json }>('GET', optionsPath);
    const credential = (await navigator.credentials.get({ publicKey: decodeOptions(options) as unknown as PublicKeyCredentialRequestOptions })) as PublicKeyCredential | null;
    if (!credential) {
        throw new Error('cancelled');
    }
    return (await send<Json>('POST', postPath, { credential: encodeCredential(credential), ...extra })) ?? {};
}

/** Sign in with a passkey. */
export function signInWithPasskey(remember: boolean): Promise<Json> {
    return assert('/api/app/auth/passkeys/login/options', '/api/app/auth/passkeys/login', { remember });
}

/** Confirm it's you with a passkey, before a sensitive change. */
export function confirmWithPasskey(): Promise<Json> {
    return assert('/api/app/auth/passkeys/confirm/options', '/api/app/auth/passkeys/confirm');
}

/** Add a passkey to the signed-in person's account. */
export async function registerPasskey(name: string): Promise<void> {
    await ensureCsrf();
    const { options } = await send<{ options: Json }>('GET', '/api/app/auth/user/passkeys/options');
    const credential = (await navigator.credentials.create({ publicKey: decodeOptions(options) as unknown as PublicKeyCredentialCreationOptions })) as PublicKeyCredential | null;
    if (!credential) {
        throw new Error('cancelled');
    }
    await send('POST', '/api/app/auth/user/passkeys', { name, credential: encodeCredential(credential) });
}
