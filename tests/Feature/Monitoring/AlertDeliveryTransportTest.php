<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationType;
use App\Notifications\IncidentAlertNotification;
use App\Services\Monitoring\AlertNotificationTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

final class AlertDeliveryTransportTest extends TestCase
{
    use MonitoringHelpers;

    public function test_signed_webhook_pins_dns_disables_proxy_and_redirects_and_bounds_response_body(): void
    {
        $this->travelTo('2026-09-21 12:00:00 UTC');
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->with('alerts.example.com')->once()->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        $options = null;
        Http::fake(['https://alerts.example.com/events' => function (Request $request, array $settings) use (&$options) {
            $options = $settings;

            return Http::response('', 204);
        }]);

        $result = app(AlertNotificationTransport::class)->send('delivery-fixture', $this->target(), $this->payload());

        $this->assertSame(AlertDeliveryStatus::Accepted, $result->status);
        $this->assertSame(204, $result->httpStatus);
        $this->assertIsArray($options);
        $this->assertSame(['alerts.example.com:443:1.1.1.1'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
        $this->assertTrue($options['curl'][CURLOPT_FRESH_CONNECT]);
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(10, $options['timeout']);
        $this->assertSame(3, $options['connect_timeout']);
        Http::assertSent(fn (Request $request): bool => $request->header('X-Beacon-Delivery') === ['delivery-fixture']
            && $request->header('X-Beacon-Timestamp') === ['1789992000']
            && $request->header('X-Beacon-Signature') === ['v1='.hash_hmac('sha256', '1789992000.'.$request->body(), 'fixture-signing-key')]
            && $request['id'] === 'delivery-fixture' && $request['event'] === 'test');
    }

    public function test_oversized_chunked_response_is_aborted_and_never_marked_accepted(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => function (Request $request, array $options) {
            $options['sink']->write(str_repeat('a', 16385));

            return Http::response('', 200);
        }]);

        $result = app(AlertNotificationTransport::class)->send('stable-id', $this->target(), $this->payload());

        $this->assertSame(AlertDeliveryStatus::Retrying, $result->status);
        $this->assertSame('transport_result_unknown', $result->errorCode);
    }

    #[DataProvider('webhookResponses')]
    public function test_classifies_webhook_provider_responses(int $status, AlertDeliveryStatus $expected): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => Http::response('secret response never persisted', $status)]);

        $result = app(AlertNotificationTransport::class)->send('stable-id', $this->target(), $this->payload());

        $this->assertSame($expected, $result->status);
        $this->assertSame($status, $result->httpStatus);
        Http::assertSentCount(1);
    }

    /** @return array<string, list<mixed>> */
    public static function webhookResponses(): array
    {
        return [
            'accepted' => [202, AlertDeliveryStatus::Accepted], 'redirect' => [302, AlertDeliveryStatus::Failed],
            'invalid' => [400, AlertDeliveryStatus::Failed], 'unauthorized' => [401, AlertDeliveryStatus::Failed],
            'timeout' => [408, AlertDeliveryStatus::Retrying], 'rate limit' => [429, AlertDeliveryStatus::Retrying],
            'unavailable' => [503, AlertDeliveryStatus::Retrying],
        ];
    }

    public function test_connection_failure_has_no_raw_url_or_provider_error(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => Http::failedConnection('secret credentials')]);

        $result = app(AlertNotificationTransport::class)->send('stable-id', $this->target(), $this->payload());

        $this->assertSame(AlertDeliveryStatus::Retrying, $result->status);
        $this->assertSame('transport_result_unknown', $result->errorCode);
        Http::assertSentCount(1);
    }

    public function test_blocked_dns_addresses_never_make_an_http_request(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['169.254.169.254']);
        Http::preventStrayRequests();

        $result = app(AlertNotificationTransport::class)->send('stable-id', $this->target(), $this->payload());

        $this->assertSame(AlertDeliveryStatus::Failed, $result->status);
        $this->assertSame('target_not_public', $result->errorCode);
        Http::assertNothingSent();
    }

    #[DataProvider('slackResponses')]
    public function test_slack_classifies_acknowledgements_and_ambiguous_results(int $status, string $body, AlertDeliveryStatus $expected): void
    {
        config(['app.name' => 'Monitor']);
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->with('hooks.slack.com')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://hooks.slack.com/services/T123/B456/FixtureSecret' => Http::response($body, $status, ['Retry-After' => '600'])]);
        $target = $this->target();
        $target['type'] = AlertDestinationType::Slack;
        $target['endpoint'] = 'https://hooks.slack.com/services/T123/B456/FixtureSecret';

        $result = app(AlertNotificationTransport::class)->send('stable-id', $target, $this->payload());

        $this->assertSame($expected, $result->status);
        Http::assertSent(fn (Request $request): bool => $request['blocks'][0]['text']['type'] === 'plain_text'
            && $request['text'] === 'Monitor: Test — Test notification'
            && str_starts_with($request['blocks'][0]['text']['text'], 'Monitor: Test — Test notification')
            && ! $request->hasHeader('X-Beacon-Signature'));
    }

    /** @return array<string, list<mixed>> */
    public static function slackResponses(): array
    {
        return [
            'ok' => [200, 'ok', AlertDeliveryStatus::Accepted], 'unexpected success' => [200, 'unknown', AlertDeliveryStatus::Uncertain],
            'rate limit' => [429, 'rate_limited', AlertDeliveryStatus::Retrying], 'error' => [500, 'error', AlertDeliveryStatus::Uncertain],
            'invalid hook' => [404, 'no_service', AlertDeliveryStatus::Failed],
        ];
    }

    public function test_log_mailer_is_not_counted_as_email_delivery(): void
    {
        config(['monitoring.alerts.mailer' => 'log']);
        Notification::fake();
        $target = $this->target();
        $target['type'] = AlertDestinationType::Email;
        $target['email'] = 'recipient@example.com';

        $result = app(AlertNotificationTransport::class)->send('stable-id', $target, $this->payload());

        $this->assertSame('mail_unconfigured', $result->errorCode);
        $this->assertSame(AlertDeliveryStatus::Failed, $result->status);
        Notification::assertNothingSent();
    }

    public function test_configured_email_uses_an_immediate_notification_inside_the_delivery_worker(): void
    {
        config(['monitoring.alerts.mailer' => 'alert_smtp']);
        Notification::fake();
        $target = $this->target();
        $target['type'] = AlertDestinationType::Email;
        $target['email'] = 'recipient@example.com';

        $result = app(AlertNotificationTransport::class)->send('stable-id', $target, $this->payload());

        $this->assertSame(AlertDeliveryStatus::Accepted, $result->status);
        Notification::assertSentOnDemand(IncidentAlertNotification::class, fn ($notification, $channels, $recipient): bool => $notification->deliveryId === 'stable-id' && $channels === ['mail'] && $recipient->routes['mail'] === 'recipient@example.com');
    }

    public function test_email_html_escapes_untrusted_incident_and_application_labels(): void
    {
        $payload = $this->payload();
        $payload['title'] = '<script>alert(1)</script>';
        $payload['application'] = '<img src=x onerror=alert(1)>';

        $html = (string) (new IncidentAlertNotification('stable-id', $payload))->toMail(new stdClass)->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    /** @return array{type: AlertDestinationType, endpoint: ?string, secret: ?string, email: ?string} */
    private function target(): array
    {
        return ['type' => AlertDestinationType::Webhook, 'endpoint' => 'https://alerts.example.com/events', 'secret' => 'fixture-signing-key', 'email' => null];
    }

    #[DataProvider('mailOverrides')]
    public function test_mail_configuration_checks_effective_url_overrides(string $url, bool $configured): void
    {
        config(['monitoring.alerts.mailer' => 'alert_smtp', 'mail.mailers.alert_smtp.url' => $url]);

        $this->assertSame($configured, app(AlertNotificationTransport::class)->mailConfigured());
    }

    /** @return array<string, list<mixed>> */
    public static function mailOverrides(): array
    {
        return [
            'real bounded SMTP' => ['smtp://user:password@mail.example.com:587?timeout=10', true],
            'log override' => ['log://mail.example.com', false],
            'unbounded override' => ['smtp://mail.example.com?timeout=0', false],
            'excessive timeout' => ['smtp://mail.example.com?timeout=600', false],
            'query driver override' => ['smtp://mail.example.com?driver=log', false],
            'malformed port' => ['smtp://mail.example.com:999999', false],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['event' => 'test', 'title' => 'Test notification', 'application' => 'Demo', 'environment' => 'Production', 'url' => null, 'rule' => null, 'observation' => null];
    }
}
