<?php

namespace Tests\Feature\Monitor;

use App\Modules\Monitor\Contracts\DnsResolver;
use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Services\PublicWebhookTarget;
use Tests\TestCase;

final class PublicWebhookTargetTest extends TestCase
{
    public function test_supported_provider_urls_are_limited_to_their_expected_hosts_and_paths(): void
    {
        $target = $this->target(['8.8.8.8']);

        $this->assertSame('hooks.slack.com', $target->host(
            'https://hooks.slack.com/services/T123/B456/secret',
            AlertDestinationType::Slack,
        ));
        $this->assertSame('outlook.office.com', $target->host(
            'https://outlook.office.com/webhook/tenant/connector',
            AlertDestinationType::Teams,
        ));
        $this->assertSame('events.pagerduty.com', $target->host(
            'https://events.pagerduty.com/v2/enqueue',
            AlertDestinationType::PagerDuty,
        ));
        $this->assertSame('discord.com', $target->host(
            'https://discord.com/api/webhooks/123456/token_value',
            AlertDestinationType::Discord,
        ));
        $this->assertSame('alerts.example.test', $target->host(
            'https://alerts.example.test/monitor/events',
            AlertDestinationType::Webhook,
        ));
    }

    public function test_provider_url_validation_rejects_credentials_fragments_queries_and_wrong_destinations(): void
    {
        $target = $this->target(['8.8.8.8']);
        $invalidTargets = [
            ['https://user:secret@hooks.slack.com/services/T123/B456/secret', AlertDestinationType::Slack],
            ['https://hooks.slack.com/services/T123/B456/secret?redirect=https://internal.test', AlertDestinationType::Slack],
            ['https://hooks.slack.com:8443/services/T123/B456/secret', AlertDestinationType::Slack],
            ['https://hooks.slack.com/services/T123/B456/secret#fragment', AlertDestinationType::Slack],
            ['https://events.pagerduty.com/v1/enqueue', AlertDestinationType::PagerDuty],
            ['https://discord.com/api/webhooks/123456/token?wait=true', AlertDestinationType::Discord],
            ['http://alerts.example.test/monitor/events', AlertDestinationType::Webhook],
            ["https://alerts.example.test/monitor/\nevents", AlertDestinationType::Webhook],
        ];

        foreach ($invalidTargets as [$url, $type]) {
            $this->assertNull($target->host($url, $type), "Unsafe destination should be rejected: {$url}");
        }
    }

    public function test_dns_resolution_rejects_private_mixed_and_unavailable_address_sets(): void
    {
        $validUrl = 'https://alerts.example.test/monitor/events';

        $public = $this->target(['8.8.8.8'])->resolve($validUrl, AlertDestinationType::Webhook);
        $mixed = $this->target(['8.8.8.8', '127.0.0.1'])->resolve($validUrl, AlertDestinationType::Webhook);
        $private = $this->target(['10.20.30.40'])->resolve($validUrl, AlertDestinationType::Webhook);
        $unavailable = $this->target([])->resolve($validUrl, AlertDestinationType::Webhook);

        $this->assertSame(['host' => 'alerts.example.test', 'address' => '8.8.8.8', 'error' => null], $public);
        $this->assertSame('target_not_public', $mixed['error']);
        $this->assertNull($mixed['address']);
        $this->assertSame('target_not_public', $private['error']);
        $this->assertSame('dns_unavailable', $unavailable['error']);
    }

    private function target(array $addresses): PublicWebhookTarget
    {
        $resolver = new class($addresses) implements DnsResolver
        {
            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses) {}

            public function addresses(string $hostname): array
            {
                return $this->addresses;
            }
        };

        return new PublicWebhookTarget($resolver);
    }
}
