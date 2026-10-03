<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\FinishesSsoSignIn;
use App\Services\Identity\AccountSaml;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** `/sso/saml/acs`: where a SAML identity provider posts its response. */
final class ConsumeSamlResponseController
{
    use FinishesSsoSignIn;

    /**
     * Check the posted response and hand the result to the finish page (which has the session) by redirect.
     *
     * @param  Request  $request
     * @param  AccountSaml  $saml
     * @return RedirectResponse
     */
    public function __invoke(Request $request, AccountSaml $saml): RedirectResponse
    {
        if (! is_string($request->input('SAMLResponse'))) {
            return $this->ssoFailed($request, __('Single sign-on was cancelled.'));
        }
        try {
            $token = $saml->consume($request->string('SAMLResponse')->toString());
        } catch (RuntimeException $exception) {
            report($exception);

            return $this->ssoFailed($request, $exception->getMessage());
        }

        return redirect()->route('sso.saml.finish', ['token' => $token], 303);
    }
}
