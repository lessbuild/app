<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\RecordAuditEntry;
use App\Http\Controllers\Auth\Concerns\FinishesSsoSignIn;
use App\Services\Identity\AccountSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SsoCallbackController
{
    use FinishesSsoSignIn;

    /**
     * Finish single sign-on: sign the person in, or record that a signed-in person proved who they are. The provider's
     * email must belong to a member of the account; nobody is created or added here.
     *
     * @param  Request  $request
     * @param  AccountSso  $sso
     * @param  RecordAuditEntry  $audit
     * @return RedirectResponse
     */
    public function __invoke(Request $request, AccountSso $sso, RecordAuditEntry $audit): RedirectResponse
    {
        if (! is_string($request->query('code')) || ! is_string($request->query('state'))) {
            return $this->ssoFailed($request, is_string($request->query('error_description')) ? $request->query('error_description') : __('Single sign-on was cancelled.'));
        }
        try {
            ['account' => $account, 'email' => $email, 'intent' => $intent] = $sso->complete($request->session(), $request->query('code'), $request->query('state'));
        } catch (RuntimeException $exception) {
            report($exception);

            return $this->ssoFailed($request, $exception->getMessage());
        }

        return $this->finishSso($request, $account, $email, $intent, $sso, $audit);
    }
}
