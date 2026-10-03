<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class PageModalsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that form pages open in modals on their lists (the pages still work on their own), and that a failed
     * submit reopens the modal with the errors kept for the form's reload.
     *
     * @return void
     */
    public function test_form_pages_open_in_modals_and_come_back_with_their_errors(): void
    {
        $project = Project::factory()->withServices(['monitoring', 'deploy'])->create();
        $owner = $this->ownerOf($project);

        foreach ([
            'monitoring.monitors' => ['add-monitor', 'monitoring.monitors.create'],
            'monitoring.rules' => ['add-rule', 'monitoring.rules.create'],
            'monitoring.objectives' => ['add-objective', 'monitoring.objectives.create'],
            'deploy.repositories' => ['connect-repository', 'deploy.repositories.create'],
        ] as $list => [$id, $formRoute]) {
            $form = route($formRoute, $project);
            $this->actingAs($owner)->get(route($list, $project))->assertOk()
                ->assertSee('data-modal-trigger="'.$id.'"', false)->assertSee('id="'.$id.'"', false)->assertSee('data-fragment-src="'.$form.'"', false);
            $this->actingAs($owner)->get($form)->assertOk()->assertSee('data-modal-content', false);
        }

        $this->actingAs($owner)->from(route('monitoring.objectives', $project))->followingRedirects()
            ->post(route('monitoring.objectives.store', $project), ['_modal' => 'add-objective', 'name' => ''])->assertOk()
            ->assertSee('data-modal-initial-open="true"', false);
        // The errors survive one more request, so the form the modal loads shows them.
        $this->actingAs($owner)->withHeaders(['X-Fragment' => '1', 'X-Requested-With' => 'XMLHttpRequest'])->get(route('monitoring.objectives.create', $project))
            ->assertOk()->assertSee('aria-invalid="true"', false);
    }

    /**
     * Check the fixed footer: the platform's status and quick links on every signed-in page.
     *
     * @return void
     */
    public function test_the_app_has_a_fixed_footer(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerOf($project))->get('/dashboard')->assertOk()
            ->assertSee('class="app-footer fixed', false)->assertSee(route('platform.status'), false)
            ->assertSee(__('Help'))->assertSee(__('Feedback'))->assertSee('data-signal-command-open', false);
        $this->get('/login')->assertDontSee('app-footer');
    }
}
