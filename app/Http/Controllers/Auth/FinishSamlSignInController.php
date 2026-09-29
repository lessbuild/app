<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\RecordAuditEntry;
use App\Http\Controllers\Auth\Concerns\FinishesSsoSignIn;
use App\Services\Identity\AccountSaml;
use App\Services\Identity\AccountSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** `/sso/saml/finish`: collects a checked SAML response in the browser that started the sign-in. */
final class FinishSamlSignInController
{
    use FinishesSsoSignIn;

    /**
     * Collect the result and finish signing in (or proving it's you).
     *
     * @param  Request  $request
     * @param  AccountSaml  $saml
     * @param  AccountSso  $sso
     * @param  RecordAuditEntry  $audit
     * @return RedirectResponse
     */
    public function __invoke(Request $request, AccountSaml $saml, AccountSso $sso, RecordAuditEntry $audit): RedirectResponse
    {
        try {
            ['account' => $account, 'email' => $email, 'intent' => $intent] = $saml->finish($request->session(), $request->string('token')->toString());
        } catch (RuntimeException $exception) {
            return $this->ssoFailed($request, $exception->getMessage());
        }

        return $this->finishSso($request, $account, $email, $intent, $sso, $audit);
    }
}
