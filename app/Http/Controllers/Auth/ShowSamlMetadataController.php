<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\Account;
use App\Services\Identity\AccountSaml;
use Illuminate\Http\Response;

/** `/sso/saml/{account}/metadata`: this service's SAML metadata, for the account's identity provider. */
final class ShowSamlMetadataController
{
    /**
     * Send the service provider metadata (entity ID, assertion consumer URL, name ID format) as XML.
     *
     * @param  string  $account
     * @param  AccountSaml  $saml
     * @return Response
     */
    public function __invoke(string $account, AccountSaml $saml): Response
    {
        $record = Account::query()->findOrFail($account);

        return response($saml->metadata($record), 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }
}
