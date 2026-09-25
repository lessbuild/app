<?php

namespace Tests\Feature\Core;

use App\Modules\Deployer\Actions\AccessRequest\SubmitAccessRequestAction;
use App\Modules\Deployer\Services\RegistrationAccess;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

final class CoreAccessRequestTest extends TestCase
{
    public function test_core_access_request_uses_the_shared_signal_form_and_preserves_plan_selection(): void
    {
        $registration = Mockery::mock(RegistrationAccess::class);
        $registration->shouldReceive('allowsNewUser')->once()->andReturn(false);
        $this->app->instance(RegistrationAccess::class, $registration);

        $this->get(route('core.access-request.create', ['plan' => 'pro']))
            ->assertOk()
            ->assertSee('Request Deployer access')
            ->assertSee('Private and encrypted at rest')
            ->assertSee('value="pro" selected', false)
            ->assertSee('action=\"'.route('core.access-request.store').'\"', false)
            ->assertSee(route('core.privacy'), false)
            ->assertSee(route('core.pricing'), false)
            ->assertSee('No payment or cloud credentials required');
    }

    public function test_core_access_request_reuses_the_existing_deployer_intake_action(): void
    {
        $registration = Mockery::mock(RegistrationAccess::class);
        $registration->shouldReceive('allowsNewUser')->once()->andReturnFalse();
        $this->app->instance(RegistrationAccess::class, $registration);

        $submit = Mockery::mock(SubmitAccessRequestAction::class);
        $submit->shouldReceive('handle')
            ->once()
            ->with([
                'name' => 'Nora Corkish',
                'email' => 'nora@example.test',
                'company' => 'Buildpusher',
                'team_size' => '2-5',
                'plan' => 'pro',
                'use_case' => 'Deploy and safely recover several production applications.',
            ]);
        $this->app->instance(SubmitAccessRequestAction::class, $submit);

        $this->post(route('core.access-request.store'), [
            'name' => ' Nora Corkish ',
            'email' => ' NORA@example.test ',
            'company' => ' Buildpusher ',
            'team_size' => '2-5',
            'plan' => 'pro',
            'use_case' => ' Deploy and safely recover several production applications. ',
        ])
            ->assertRedirect(route('core.access-request.create'))
            ->assertSessionHas('access_requested');
    }

    public function test_open_registration_sends_core_access_requests_to_core_registration(): void
    {
        $registration = Mockery::mock(RegistrationAccess::class);
        $registration->shouldReceive('allowsNewUser')->once()->andReturn(true);
        $this->app->instance(RegistrationAccess::class, $registration);

        $this->get(route('core.access-request.create'))
            ->assertRedirect(route('platform.register'));
    }

    public function test_the_existing_deployer_access_request_route_remains_available(): void
    {
        $this->assertTrue(Route::has('access-request.create'));
        $this->assertTrue(Route::has('access-request.store'));
    }
}
