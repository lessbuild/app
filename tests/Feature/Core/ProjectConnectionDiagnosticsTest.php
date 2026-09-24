<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectConnectionDiagnosticProvider;
use App\Core\Data\Connections\ProjectConnectionDiagnostic;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\ProjectResource;
use App\Core\Services\Connections\ProjectConnectionDiagnosticRegistry;
use App\Core\Services\Connections\ProjectConnectionDiagnostics;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class ProjectConnectionDiagnosticsTest extends TestCase
{
    public function test_known_delivery_errors_have_safe_explanations_and_next_steps(): void
    {
        $connection = (new ProjectConnection)->forceFill([
            'status' => 'failed',
            'last_error_code' => 'target_delivery_failed',
            'last_error_at' => now()->subMinute(),
            'last_succeeded_at' => now()->subHour(),
        ]);
        $delivery = (new ProjectConnectionDelivery)->forceFill([
            'status' => 'blocked',
            'attempts' => 2,
            'last_error_code' => 'delivery_payload_conflict',
            'last_attempted_at' => now()->subMinute(),
        ]);
        $connection->setRelation('deliveries', collect([$delivery]));

        $diagnostic = (new ProjectConnectionDiagnostics(new ProjectConnectionDiagnosticRegistry))->forConnection($connection);
        $html = Blade::render('<x-signal.ui.project-connection-diagnostic :diagnostic="$diagnostic" />', [
            'diagnostic' => $diagnostic,
        ]);

        $this->assertSame('danger', $diagnostic->tone);
        $this->assertSame('Event conflict', $diagnostic->status);
        $this->assertSame('Review the source event and contact an app admin before retrying.', $diagnostic->nextStep);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('Last successful update', $html);
        $this->assertStringNotContainsString('delivery_payload_conflict', $html);
        $this->assertStringNotContainsString('target_delivery_failed', $html);
    }

    public function test_unknown_error_codes_use_generic_redacted_guidance(): void
    {
        $connection = (new ProjectConnection)->forceFill([
            'status' => 'failed',
            'last_error_code' => 'provider-token-leaked-in-bad-code',
        ]);
        $connection->setRelation('deliveries', collect());

        $diagnostic = (new ProjectConnectionDiagnostics(new ProjectConnectionDiagnosticRegistry))->forConnection($connection);
        $html = Blade::render('<x-signal.ui.project-connection-diagnostic :diagnostic="$diagnostic" />', [
            'diagnostic' => $diagnostic,
        ]);

        $this->assertSame('Needs attention', $diagnostic->status);
        $this->assertStringContainsString('Sensitive provider details are hidden.', $html);
        $this->assertStringNotContainsString('provider-token-leaked-in-bad-code', $html);
    }

    public function test_access_and_plan_error_codes_have_safe_specific_next_steps(): void
    {
        $scenarios = [
            ['product_access_changed', 'Access changed', 'Restore the required workspace app access'],
            ['connection_unavailable', 'Mapping needs review', 'Review both project resources'],
            ['product_subscription_unavailable', 'Subscription needs attention', 'Review the subscription'],
            ['product_feature_not_included', 'Plan needs review', 'Review the plans for both connected apps'],
            ['product_limit_unavailable', 'Plan limit needs review', 'Review the receiving app plan'],
        ];

        foreach ($scenarios as [$errorCode, $expectedStatus, $expectedNextStep]) {
            $connection = (new ProjectConnection)->forceFill([
                'status' => 'failed',
                'last_error_code' => $errorCode,
            ]);
            $connection->setRelation('deliveries', collect());

            $diagnostic = (new ProjectConnectionDiagnostics(new ProjectConnectionDiagnosticRegistry))->forConnection($connection);
            $html = Blade::render('<x-signal.ui.project-connection-diagnostic :diagnostic="$diagnostic" />', [
                'diagnostic' => $diagnostic,
            ]);

            $this->assertSame($expectedStatus, $diagnostic->status);
            $this->assertStringContainsString($expectedNextStep, $html);
            $this->assertStringNotContainsString($errorCode, $html);
        }
    }

    public function test_waiting_connection_has_a_clear_first_event_state(): void
    {
        $connection = (new ProjectConnection)->forceFill(['status' => 'pending']);
        $connection->setRelation('deliveries', collect());

        $diagnostic = (new ProjectConnectionDiagnostics(new ProjectConnectionDiagnosticRegistry))->forConnection($connection);

        $this->assertSame('info', $diagnostic->tone);
        $this->assertSame('Waiting', $diagnostic->status);
        $this->assertSame('Waiting for the first matching app event.', $diagnostic->summary);
    }

    public function test_read_only_traffic_context_is_not_misreported_as_missing_events(): void
    {
        $connection = (new ProjectConnection)->forceFill([
            'status' => 'active',
            'capabilities' => [ProjectConnectionCapability::TrafficContext->value],
        ]);
        $connection->setRelation('deliveries', collect());

        $diagnostic = (new ProjectConnectionDiagnostics(new ProjectConnectionDiagnosticRegistry))->forConnection($connection);

        $this->assertSame('Available on demand', $diagnostic->status);
        $this->assertSame('Read-only traffic context is available for Monitor investigations.', $diagnostic->summary);
    }

    public function test_product_database_failure_is_redacted_in_connection_diagnostics(): void
    {
        $registry = new ProjectConnectionDiagnosticRegistry;
        $registry->register('monitor', new class implements ProjectConnectionDiagnosticProvider
        {
            public function diagnose(PlatformUser $user, ProjectConnection $connection, ProjectResource $resource): ?ProjectConnectionDiagnostic
            {
                throw new \PDOException('private-database-host and token-secret');
            }
        });
        $resource = (new ProjectResource)->forceFill([
            'product' => 'monitor',
            'resource_type' => 'environment',
            'status' => 'active',
        ]);
        $connection = (new ProjectConnection)->forceFill(['status' => 'active']);
        $connection->setRelation('deliveries', collect());
        $connection->setRelation('targetResource', $resource);

        $diagnostic = (new ProjectConnectionDiagnostics($registry))->forConnection($connection, new PlatformUser);
        $html = Blade::render('<x-signal.ui.project-connection-diagnostic :diagnostic="$diagnostic" />', [
            'diagnostic' => $diagnostic,
        ]);

        $this->assertSame('App data unavailable', $diagnostic->status);
        $this->assertStringContainsString('could not be checked', $html);
        $this->assertStringNotContainsString('private-database-host', $html);
        $this->assertStringNotContainsString('token-secret', $html);
    }

    public function test_product_diagnostic_cannot_hide_a_saved_delivery_error(): void
    {
        $registry = new ProjectConnectionDiagnosticRegistry;
        $called = (object) ['value' => false];
        $registry->register('monitor', new class($called) implements ProjectConnectionDiagnosticProvider
        {
            public function __construct(private object $called) {}

            public function diagnose(PlatformUser $user, ProjectConnection $connection, ProjectResource $resource): ?ProjectConnectionDiagnostic
            {
                $this->called->value = true;

                return null;
            }
        });
        $resource = (new ProjectResource)->forceFill([
            'product' => 'monitor',
            'resource_type' => 'environment',
            'status' => 'active',
        ]);
        $connection = (new ProjectConnection)->forceFill([
            'status' => 'active',
            'last_error_code' => 'target_delivery_failed',
        ]);
        $connection->setRelation('deliveries', collect());
        $connection->setRelation('targetResource', $resource);

        $diagnostic = (new ProjectConnectionDiagnostics($registry))->forConnection($connection, new PlatformUser);

        $this->assertSame('Delivery failed', $diagnostic->status);
        $this->assertFalse($called->value);
    }

    public function test_latest_product_activity_is_labeled_separately_from_delivery_attempts(): void
    {
        $diagnostic = new ProjectConnectionDiagnostic(
            tone: 'info',
            status: 'Telemetry received',
            summary: 'Monitor has received telemetry for this environment.',
            detail: 'The latest event arrived recently.',
            nextStep: null,
            lastAttemptAt: null,
            lastSucceededAt: null,
            lastObservedAt: now()->subMinute(),
        );
        $html = Blade::render('<x-signal.ui.project-connection-diagnostic :diagnostic="$diagnostic" />', [
            'diagnostic' => $diagnostic,
        ]);

        $this->assertStringContainsString('Latest telemetry', $html);
        $this->assertStringNotContainsString('Last attempt', $html);
    }
}
