<?php

namespace Tests\Feature\Core;

use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
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

        $diagnostic = (new ProjectConnectionDiagnostics)->forConnection($connection);
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

        $diagnostic = (new ProjectConnectionDiagnostics)->forConnection($connection);
        $html = Blade::render('<x-signal.ui.project-connection-diagnostic :diagnostic="$diagnostic" />', [
            'diagnostic' => $diagnostic,
        ]);

        $this->assertSame('Needs attention', $diagnostic->status);
        $this->assertStringContainsString('Sensitive provider details are hidden.', $html);
        $this->assertStringNotContainsString('provider-token-leaked-in-bad-code', $html);
    }

    public function test_waiting_connection_has_a_clear_first_event_state(): void
    {
        $connection = (new ProjectConnection)->forceFill(['status' => 'pending']);
        $connection->setRelation('deliveries', collect());

        $diagnostic = (new ProjectConnectionDiagnostics)->forConnection($connection);

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

        $diagnostic = (new ProjectConnectionDiagnostics)->forConnection($connection);

        $this->assertSame('Available on demand', $diagnostic->status);
        $this->assertSame('Read-only traffic context is available for Monitor investigations.', $diagnostic->summary);
    }
}
