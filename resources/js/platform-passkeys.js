const decodeBase64Url = (value) => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, '=');
    const binary = window.atob(padded);

    return Uint8Array.from(binary, (character) => character.charCodeAt(0));
};

const encodeBase64Url = (value) => {
    const bytes = new Uint8Array(value);
    let binary = '';

    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
    }

    return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
};

const decodePublicKeyOptions = (options) => {
    const publicKey = { ...options };

    if (publicKey.challenge) {
        publicKey.challenge = decodeBase64Url(publicKey.challenge);
    }

    if (publicKey.user?.id) {
        publicKey.user = { ...publicKey.user, id: decodeBase64Url(publicKey.user.id) };
    }

    for (const key of ['allowCredentials', 'excludeCredentials']) {
        if (Array.isArray(publicKey[key])) {
            publicKey[key] = publicKey[key].map((credential) => ({
                ...credential,
                id: decodeBase64Url(credential.id),
            }));
        }
    }

    return publicKey;
};

const credentialPayload = (credential) => {
    const response = credential.response;
    const payload = {
        id: credential.id,
        rawId: encodeBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: encodeBase64Url(response.clientDataJSON),
        },
        clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
    };

    if (response.attestationObject) {
        payload.response.attestationObject = encodeBase64Url(response.attestationObject);
    }
    if (response.authenticatorData) {
        payload.response.authenticatorData = encodeBase64Url(response.authenticatorData);
    }
    if (response.signature) {
        payload.response.signature = encodeBase64Url(response.signature);
    }
    if (response.userHandle) {
        payload.response.userHandle = encodeBase64Url(response.userHandle);
    }
    if (typeof response.getTransports === 'function') {
        payload.response.transports = response.getTransports();
    }
    if (credential.authenticatorAttachment) {
        payload.authenticatorAttachment = credential.authenticatorAttachment;
    }

    return payload;
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content
    ?? document.querySelector('input[name="_token"]')?.value
    ?? '';

const responseError = async (response) => {
    let payload = {};
    try {
        payload = await response.json();
    } catch {
        // The server may return an HTML error page for expired sessions.
    }

    const firstValidationError = Object.values(payload.errors ?? {})
        .flat()
        .find((message) => typeof message === 'string');

    return firstValidationError ?? payload.message ?? 'The passkey request could not be completed. Please try again.';
};

const setBusy = (button, busy, message) => {
    button.disabled = busy;
    if (message) {
        const status = button.closest('[data-passkey-surface]')?.querySelector('[data-passkey-status]');
        if (status) {
            status.textContent = message;
        }
    }
};

const appendFields = (form, name, value) => {
    if (value === null || value === undefined) {
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item) => appendFields(form, `${name}[]`, item));
        return;
    }

    if (typeof value === 'object') {
        Object.entries(value).forEach(([key, item]) => appendFields(form, `${name}[${key}]`, item));
        return;
    }

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = String(value);
    form.append(input);
};

document.querySelectorAll('[data-passkey-login]').forEach((button) => {
    button.addEventListener('click', async () => {
        if (!navigator.credentials?.get) {
            setBusy(button, false, button.dataset.unsupportedMessage);
            return;
        }

        setBusy(button, true, button.dataset.workingMessage);

        try {
            const optionsResponse = await fetch(button.dataset.optionsUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            });
            if (!optionsResponse.ok) {
                throw new Error(await responseError(optionsResponse));
            }

            const { options } = await optionsResponse.json();
            const credential = await navigator.credentials.get({ publicKey: decodePublicKeyOptions(options) });
            if (!credential) {
                throw new Error(button.dataset.failedMessage);
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = button.dataset.loginUrl;
            form.hidden = true;
            appendFields(form, '_token', csrfToken());
            appendFields(form, 'credential', credentialPayload(credential));
            appendFields(form, 'remember', button.closest('form')?.querySelector('input[type="checkbox"][name="remember"]')?.checked ? '1' : '0');
            if (button.dataset.returnTo) {
                appendFields(form, 'return_to', button.dataset.returnTo);
            }
            document.body.append(form);
            form.submit();
        } catch (error) {
            setBusy(button, false, error instanceof Error ? error.message : button.dataset.failedMessage);
        }
    });
});

document.querySelectorAll('[data-passkey-registration]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        if (!button) {
            return;
        }
        if (!navigator.credentials?.create) {
            setBusy(button, false, form.dataset.unsupportedMessage);
            return;
        }

        setBusy(button, true, form.dataset.workingMessage);

        try {
            const optionsResponse = await fetch(form.dataset.optionsUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: new FormData(form),
            });
            if (!optionsResponse.ok) {
                throw new Error(await responseError(optionsResponse));
            }

            const { options } = await optionsResponse.json();
            const credential = await navigator.credentials.create({ publicKey: decodePublicKeyOptions(options) });
            if (!credential) {
                throw new Error(form.dataset.failedMessage);
            }

            const name = new FormData(form).get('name');
            const storeResponse = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ name, credential: credentialPayload(credential) }),
            });
            if (!storeResponse.ok) {
                throw new Error(await responseError(storeResponse));
            }

            window.location.reload();
        } catch (error) {
            setBusy(button, false, error instanceof Error ? error.message : form.dataset.failedMessage);
        }
    });
});
