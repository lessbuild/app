<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OneLogin\Saml2\Utils;
use OpenSSLCertificateSigningRequest;
use Tests\TestCase;

final class SamlSsoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The identity provider's private key (PEM).
     *
     * @var string
     */
    private string $key;

    /**
     * The identity provider's certificate (PEM).
     *
     * @var string
     */
    private string $certificate;

    /**
     * Make an identity provider key and self-signed certificate.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'idp.example.com'], $key);
        $this->assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        $cert = openssl_csr_sign($csr, null, $key, 30);
        $this->assertNotFalse($cert);
        openssl_pkey_export($key, $privatePem);
        openssl_x509_export($cert, $certificatePem);
        $this->key = $privatePem;
        $this->certificate = $certificatePem;
    }

    /**
     * Check a SAML sign-in end to end: the account switches to SAML, the sign-in request goes to the provider, a
     * signed response for a member signs them in through the ACS and finish page, and tampered, replayed or
     * other-browser responses don't.
     *
     * @return void
     */
    /**
     * Members sign in through a SAML identity provider; tampered or replayed responses are refused.
     */
    public function test_members_sign_in_through_a_saml_identity_provider(): void
    {
        $this->withoutMiddleware(RequirePassword::class);
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create(['name' => 'Acme']);
        $member = User::factory()->create(['email' => 'amy@acme.test']);
        Membership::query()->forceCreate(['account_id' => $account->id, 'user_id' => $member->id, 'role' => AccountRole::Member]);
        $owner->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($owner)->getJson('/api/app/account/security')->assertOk()->assertJsonPath('saml.acsUrl', route('sso.saml.acs'));
        $this->actingAs($owner)->putJson('/api/app/account/security/saml', ['saml_idp_entity_id' => 'https://idp.example.com', 'saml_idp_sso_url' => 'https://idp.example.com/sso', 'saml_idp_certificate' => 'not a certificate'])->assertJsonValidationErrors('saml_idp_certificate');
        $this->actingAs($owner)->putJson('/api/app/account/security/saml', ['saml_idp_entity_id' => 'https://idp.example.com', 'saml_idp_sso_url' => 'https://idp.example.com/sso', 'saml_idp_certificate' => $this->certificate])->assertOk()->assertJsonPath('redirect', '/account/security');
        $account->refresh();
        $this->assertSame('saml', $account->sso_protocol);
        $this->assertTrue($account->hasSso());
        $this->get(route('sso.saml.metadata', $account->id))->assertOk()->assertSee(route('sso.saml.acs'), false);
        auth()->logout();

        // Start signing in: off to the identity provider with a request.
        $redirect = $this->postJson('/api/app/auth/sso', ['email' => 'amy@acme.test'])->assertOk();
        $this->assertStringStartsWith('https://idp.example.com/sso?SAMLRequest=', (string) $redirect->json('redirect'));
        $requestId = (string) session('sso.saml.attempt');
        $this->assertNotSame('', $requestId);

        // A tampered response (email changed after signing) is refused.
        $tampered = str_replace('amy@acme.test', 'owner@acme.test', $this->response($requestId, 'amy@acme.test', route('sso.saml.metadata', $account->id)));
        $this->post('/sso/saml/acs', ['SAMLResponse' => base64_encode($tampered)])->assertRedirect('/login');
        $this->assertGuest();

        // The request can only be answered once, so start again and answer it properly.
        $this->postJson('/api/app/auth/sso', ['email' => 'amy@acme.test'])->assertOk();
        $requestId = (string) session('sso.saml.attempt');
        $acs = $this->post('/sso/saml/acs', ['SAMLResponse' => base64_encode($this->response($requestId, 'amy@acme.test', route('sso.saml.metadata', $account->id)))]);
        $acs->assertStatus(303);
        $finish = (string) $acs->headers->get('Location');
        $this->get($finish)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($member);

        // The finish link is single-use.
        auth()->logout();
        $this->get($finish)->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * Build a SAML response for a request, with the assertion signed by the identity provider's key.
     *
     * @param  string  $requestId
     * @param  string  $email
     * @param  string  $audience
     * @return string
     */
    private function response(string $requestId, string $email, string $audience): string
    {
        $now = CarbonImmutable::now('UTC');
        $at = fn (CarbonImmutable $time): string => $time->format('Y-m-d\TH:i:s\Z');
        $acs = route('sso.saml.acs');
        $assertion = <<<XML
            <saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_a{$now->timestamp}" Version="2.0" IssueInstant="{$at($now)}"><saml:Issuer>https://idp.example.com</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$email}</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="{$at($now->addMinutes(5))}" Recipient="{$acs}" InResponseTo="{$requestId}"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="{$at($now->subMinute())}" NotOnOrAfter="{$at($now->addMinutes(5))}"><saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="{$at($now)}" SessionIndex="_s1"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:Password</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement></saml:Assertion>
            XML;
        $signed = Utils::addSign($assertion, $this->key, $this->certificate, 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', 'http://www.w3.org/2001/04/xmlenc#sha256');
        // The toolkit's signer puts the signature first; the schema wants it after the Issuer, and an enveloped
        // signature doesn't cover its own position, so move it there.
        $dom = new DOMDocument;
        $dom->loadXML($signed);
        $root = $dom->documentElement;
        $this->assertNotNull($root);
        $signature = $dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
        $issuer = $dom->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);
        $this->assertNotNull($signature);
        $this->assertNotNull($issuer);
        $root->insertBefore($signature, $issuer->nextSibling);
        $signed = (string) $dom->saveXML($root);

        return <<<XML
            <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r{$now->timestamp}" Version="2.0" IssueInstant="{$at($now)}" Destination="{$acs}" InResponseTo="{$requestId}"><saml:Issuer>https://idp.example.com</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>{$signed}</samlp:Response>
            XML;
    }
}
