<?php

namespace Tests\Unit\Core;

use App\Core\Data\Credentials\CredentialMutationOutcome;
use PHPUnit\Framework\TestCase;

final class CredentialMutationOutcomeTest extends TestCase
{
    public function test_secret_is_excluded_from_json_debug_and_php_serialization(): void
    {
        $outcome = new CredentialMutationOutcome('issued', 'monitor:ingest-token:42', 'ingest-token', 'Collector', 'private-one-time-secret');

        $this->assertSame('private-one-time-secret', $outcome->secretForImmediateResponse());
        $this->assertStringNotContainsString('private-one-time-secret', json_encode($outcome, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-one-time-secret', serialize($outcome));
        $this->assertStringNotContainsString('private-one-time-secret', print_r($outcome, true));

        $recovered = unserialize(serialize($outcome));
        $this->assertInstanceOf(CredentialMutationOutcome::class, $recovered);
        $this->assertNull($recovered->secretForImmediateResponse());
    }
}
