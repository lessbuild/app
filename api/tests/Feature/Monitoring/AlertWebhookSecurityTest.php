<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Enums\AlertDestinationType;
use App\Services\Monitoring\PublicWebhookTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AlertWebhookSecurityTest extends TestCase
{
    use MonitoringHelpers;

    #[DataProvider('unsafeUrls')]
    public function test_rejects_unsafe_or_ambiguous_urls(string $url): void
    {
        $target = app(PublicWebhookTarget::class);

        $this->assertNull($target->host($url, AlertDestinationType::Webhook));
    }

    /** @return array<string, list<mixed>> */
    public static function unsafeUrls(): array
    {
        return [
            'http' => ['http://alerts.example.com/events'], 'ftp' => ['ftp://alerts.example.com/events'],
            'local IP' => ['https://127.0.0.1/a'], 'public IP literal' => ['https://1.1.1.1/a'],
            'IPv6 literal' => ['https://[2606:4700:4700::1111]/a'], 'integer address' => ['https://2130706433/a'],
            'octal IP' => ['https://0177.0.0.1/a'], 'hex IP' => ['https://0x7f000001/a'],
            'single label' => ['https://localhost/a'], 'trailing dot' => ['https://alerts.example.com./a'],
            'userinfo' => ['https://user:secret@alerts.example.com/a'], 'empty userinfo' => ['https://@alerts.example.com/a'],
            'fragment' => ['https://alerts.example.com/a#x'], 'other port' => ['https://alerts.example.com:8006/a'],
            'backslash' => ['https://alerts.example.com\@127.0.0.1/a'], 'newline' => ["https://alerts.example.com/\r\nHost: localhost"],
            'unicode' => ['https://éxample.com/a'], 'numeric TLD' => ['https://example.123/a'],
            'oversize' => ['https://alerts.example.com/'.str_repeat('a', 2048)],
        ];
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_rejects_non_public_addresses(string $address): void
    {
        $this->assertFalse(app(PublicWebhookTarget::class)->isPublic($address));
    }

    /** @return array<string, list<mixed>> */
    public static function nonPublicAddresses(): array
    {
        $addresses = ['0.1.2.3', '10.1.2.3', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.0.1',
            '192.0.0.1', '192.0.2.1', '192.88.99.1', '192.168.1.1', '198.18.0.1', '198.51.100.1', '203.0.113.1',
            '224.0.0.1', '255.255.255.255', '::', '::1', '::ffff:1.1.1.1', '64:ff9b::a9fe:a9fe', 'fc00::1', 'fe80::1',
            'ff00::1', '2001:db8::1', '2001::1', '2002:7f00:1::1', '3fff::1', 'not-an-ip'];

        return array_combine($addresses, array_map(fn (string $address): array => [$address], $addresses));
    }

    public function test_accepts_public_ipv4_and_ipv6_and_canonicalizes_hostnames(): void
    {
        $target = app(PublicWebhookTarget::class);

        $this->assertTrue($target->isPublic('1.1.1.1'));
        $this->assertTrue($target->isPublic('2606:4700:4700::1111'));
        $this->assertSame('alerts.example.com', $target->host('https://ALERTS.example.com:443/path?key=value', AlertDestinationType::Webhook));
    }

    public function test_rejects_entire_dns_answer_if_one_address_is_private(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->with('alerts.example.com')->andReturn(['1.1.1.1', '10.0.0.1']);

        $result = app(PublicWebhookTarget::class)->resolve('https://alerts.example.com/a', AlertDestinationType::Webhook);

        $this->assertSame('target_not_public', $result['error']);
        $this->assertNull($result['address']);
    }

    public function test_distinguishes_dns_failure_from_an_invalid_target(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->with('alerts.example.com')->andReturn([]);

        $this->assertSame('dns_unavailable', app(PublicWebhookTarget::class)->resolve('https://alerts.example.com/a', AlertDestinationType::Webhook)['error']);
    }

    public function test_slack_requires_its_exact_host_and_incoming_webhook_path(): void
    {
        $target = app(PublicWebhookTarget::class);

        $this->assertSame('hooks.slack.com', $target->host('https://hooks.slack.com/services/T123/B456/AbCd', AlertDestinationType::Slack));
        $this->assertNull($target->host('https://hooks.slack.com.evil.com/services/T123/B456/AbCd', AlertDestinationType::Slack));
        $this->assertNull($target->host('https://hooks.slack.com/services/T123/B456/AbCd?x=1', AlertDestinationType::Slack));
        $this->assertNull($target->host('https://hooks.slack.com/other', AlertDestinationType::Slack));
    }
}
