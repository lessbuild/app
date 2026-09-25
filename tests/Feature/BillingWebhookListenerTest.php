<?php

namespace Tests\Feature;

use App\Modules\Deployer\Jobs\SyncOrganizationSeatQuantityJob;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\DeployerPlanAuthority;
use App\Modules\Deployer\Services\SyncDeployerBillingEventIntoCore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Mockery;
use Tests\TestCase;

class BillingWebhookListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_listener_dispatches_once_for_explicit_workspace_metadata(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['stripe_id' => 'cus_other']);
        Event::dispatch(new WebhookHandled(['data' => ['object' => [
            'metadata' => ['organization_id' => 321],
            'customer' => $owner->stripe_id,
        ]]]));

        Queue::assertPushed(SyncOrganizationSeatQuantityJob::class, 1);
        Queue::assertPushed(SyncOrganizationSeatQuantityJob::class, fn ($job): bool => $job->organizationId === 321);
    }

    public function test_listener_resolves_customer_workspace_when_metadata_is_absent(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['stripe_id' => 'cus_workspace']);
        Event::dispatch(new WebhookHandled(['data' => ['object' => ['customer' => $owner->stripe_id]]]));

        Queue::assertPushed(SyncOrganizationSeatQuantityJob::class, 1);
        Queue::assertPushed(SyncOrganizationSeatQuantityJob::class, fn ($job): bool => $job->organizationId === $owner->current_organization_id);
    }

    public function test_unrelated_or_unknown_customer_events_do_not_queue_reconciliation(): void
    {
        Queue::fake();
        Event::dispatch(new WebhookHandled([]));
        Event::dispatch(new WebhookHandled(['data' => ['object' => ['customer' => 'cus_unknown']]]));

        Queue::assertNothingPushed();
    }

    public function test_verified_deployer_webhook_is_projected_when_core_is_the_plan_authority(): void
    {
        $payload = ['id' => 'evt_deployer_verified', 'type' => 'customer.subscription.updated'];
        $authority = Mockery::mock(DeployerPlanAuthority::class);
        $authority->shouldReceive('usesCore')->once()->andReturn(true);
        $billingEvents = Mockery::mock(SyncDeployerBillingEventIntoCore::class);
        $billingEvents->shouldReceive('handle')->once()->with($payload);
        app()->instance(DeployerPlanAuthority::class, $authority);
        app()->instance(SyncDeployerBillingEventIntoCore::class, $billingEvents);

        Event::dispatch(new WebhookReceived($payload));
    }
}
