<?php

namespace Tests\Unit;

use App\Services\TwoFactorAuthentication;
use PHPUnit\Framework\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    public function test_it_matches_the_rfc_6238_sha1_vector_at_six_digits(): void
    {
        $service = new TwoFactorAuthentication;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertTrue($service->verifyCode($secret, '287082', 59));
        $this->assertFalse($service->verifyCode($secret, '287083', 59));
        $this->assertFalse($service->verifyCode($secret, 'not-a-code', 59));
    }

    public function test_secrets_and_recovery_codes_are_random_and_well_formed(): void
    {
        $service = new TwoFactorAuthentication;
        $firstSecret = $service->generateSecret();
        $secondSecret = $service->generateSecret();
        $codes = $service->generateRecoveryCodes();

        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $firstSecret);
        $this->assertNotSame($firstSecret, $secondSecret);
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/\A[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}\z/', $code);
        }
    }
}
