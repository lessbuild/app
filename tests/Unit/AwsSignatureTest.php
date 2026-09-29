<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AwsSignature;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class AwsSignatureTest extends TestCase
{
    /**
     * Check the signer against AWS's documented Signature Version 4 example (IAM ListUsers).
     *
     * @return void
     */
    public function test_it_matches_the_aws_signature_v4_example(): void
    {
        $headers = AwsSignature::headers(
            'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'iam', 'GET',
            'https://iam.amazonaws.com/?Action=ListUsers&Version=2010-05-08',
            ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'], '',
            CarbonImmutable::parse('2015-08-30 12:36:00', 'UTC'),
        );

        $this->assertSame('20150830T123600Z', $headers['X-Amz-Date']);
        $this->assertSame('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request, SignedHeaders=content-type;host;x-amz-date, Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7', $headers['Authorization']);
    }
}
