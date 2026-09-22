<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_starts_with_protected_production_environment(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Storefront',
            'description' => 'Customer storefront',
        ]);

        $project = Project::query()->sole();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame($owner->current_organization_id, $project->organization_id);
        $environment = $project->environments()->sole();
        $this->assertSame('production', $environment->type);
        $this->assertTrue($environment->is_protected);
        $this->assertTrue($environment->requires_deployment_approval);
    }

    public function test_developer_can_create_staging_but_cannot_change_production(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();

        $this->actingAs($developer)->post(route('environments.store', $project), $this->environmentPayload())
            ->assertRedirect();
        $this->assertDatabaseHas('environments', ['project_id' => $project->id, 'type' => 'staging']);

        $production = $project->environments()->where('type', 'production')->sole();
        $this->actingAs($developer)->patch(route('environments.update', $production), $this->environmentPayload('Production'))
            ->assertForbidden();
    }

    public function test_environment_cannot_attach_another_workspace_resource(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();
        $outsider = User::factory()->create();
        $server = $outsider->servers()->create(['name' => 'private-server']);

        $this->actingAs($developer)->post(route('environments.store', $project), [
            ...$this->environmentPayload(),
            'server_id' => $server->id,
        ])->assertSessionHasErrors('server_id');
    }

    public function test_environment_variable_is_encrypted_and_never_rendered(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();
        $environment = $project->environments()->where('type', 'staging')->firstOrFail();

        $this->actingAs($developer)->post(route('environments.variables.store', $environment), [
            'key' => 'API_SECRET',
            'value' => 'super-secret-value',
            'is_secret' => '1',
        ])->assertRedirect();

        $stored = DB::table('environment_variables')->value('value');
        $this->assertNotSame('super-secret-value', $stored);
        $this->actingAs($developer)->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('super-secret-value');
    }

    public function test_application_detail_uses_compact_runtime_panels_and_local_navigation(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();

        $this->actingAs($developer)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('data-project-environments', false)
            ->assertSee('data-project-environment', false)
            ->assertSee('data-project-runtime-controls', false)
            ->assertSee('data-project-runtime', false)
            ->assertSee('data-project-variables', false)
            ->assertSee('data-project-processes', false)
            ->assertSee('data-project-resources', false)
            ->assertSee('data-project-add-environment', false)
            ->assertSee('data-project-previews', false)
            ->assertSee('class="ui-local-nav__link"', false)
            ->assertSee('class="ui-input', false)
            ->assertSee('class="ui-panel', false)
            ->assertSee('Application sections');
    }

    public function test_environment_settings_validation_reopens_only_the_submitted_dialog(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();
        $environment = $project->environments()->where('type', 'staging')->firstOrFail();
        $dialogUrl = route('projects.show', ['project' => $project, 'dialog' => 'edit-environment-settings-'.$environment->id]);

        $this->from($dialogUrl)
            ->actingAs($developer)
            ->patch(route('environments.update', $environment), [
                '_environment_id' => $environment->id,
                '_environment_panel' => 'settings',
                'name' => '',
                'type' => 'staging',
                'branch' => 'develop',
                'is_protected' => '0',
                'requires_deployment_approval' => '0',
                'minimum_replicas' => 1,
                'maximum_replicas' => 1,
            ])
            ->assertRedirect($dialogUrl)
            ->assertSessionHasErrors('name');

        $response = $this->actingAs($developer)->get($dialogUrl);
        $response->assertOk();

        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*id="environment-settings-dialog-'.$environment->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog(?=[^>]*id="environment-deployment-controls-dialog-'.$environment->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $content,
        );
    }

    public function test_deployment_control_validation_reopens_the_deployment_dialog(): void
    {
        [$owner, $developer, $project] = $this->workspaceProject();
        $environment = $project->environments()->where('type', 'staging')->firstOrFail();
        $dialogUrl = route('projects.show', ['project' => $project, 'dialog' => 'edit-deployment-controls-'.$environment->id]);

        $this->from($dialogUrl)
            ->actingAs($developer)
            ->patch(route('environments.deployment-controls.update', $environment), [
                '_environment_id' => $environment->id,
                '_environment_panel' => 'deployment-controls',
                'deployment_locked' => '0',
                'deployment_window_enabled' => '1',
                'deployment_strategy' => 'blue_green',
                'rolling_pause_seconds' => '2',
                'automatic_rollback' => '0',
            ])
            ->assertRedirect($dialogUrl)
            ->assertSessionHasErrors('deployment_window_days');

        $response = $this->actingAs($developer)->get($dialogUrl);
        $response->assertOk();

        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*id="environment-deployment-controls-dialog-'.$environment->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog(?=[^>]*id="environment-settings-dialog-'.$environment->id.'")(?=[^>]*\sopen(?:\s|>))[^>]*>/',
            $content,
        );
    }

    public function test_application_detail_composers_use_dialogs_and_reopen_the_submitted_context(): void
    {
        config(['billing.enforce_entitlements' => false]);
        [$owner, $developer, $project] = $this->workspaceProject();
        $environment = $project->environments()->where('type', 'staging')->firstOrFail();
        $environmentSettingsDialogId = 'environment-settings-dialog-'.$environment->id;
        $deploymentControlsDialogId = 'environment-deployment-controls-dialog-'.$environment->id;
        $variableDialogId = 'environment-variable-dialog-'.$environment->id;
        $processDialogId = 'environment-process-dialog-'.$environment->id;
        $resourceDialogId = 'environment-resource-dialog-'.$environment->id;
        $dialogPattern = static fn (string $id): string => '/<dialog(?=[^>]*id="'.preg_quote($id, '/').'")(?=[^>]*\sopen(?:\s|>))[^>]*>/';

        $default = $this->actingAs($developer)->get(route('projects.show', $project))
            ->assertSuccessful()
            ->assertSee('data-modal-trigger="'.$environmentSettingsDialogId.'"', false)
            ->assertSee('data-modal-trigger="'.$deploymentControlsDialogId.'"', false)
            ->assertSee('data-modal-trigger="add-environment-dialog"', false)
            ->assertSee('data-modal-trigger="'.$variableDialogId.'"', false)
            ->assertSee('data-modal-trigger="'.$processDialogId.'"', false)
            ->assertSee('data-modal-trigger="'.$resourceDialogId.'"', false);
        $this->assertDoesNotMatchRegularExpression($dialogPattern('add-environment-dialog'), $default->getContent());
        $this->assertDoesNotMatchRegularExpression($dialogPattern($environmentSettingsDialogId), $default->getContent());
        $this->assertDoesNotMatchRegularExpression($dialogPattern($deploymentControlsDialogId), $default->getContent());
        $this->assertDoesNotMatchRegularExpression($dialogPattern($variableDialogId), $default->getContent());
        $this->assertDoesNotMatchRegularExpression($dialogPattern($processDialogId), $default->getContent());
        $this->assertDoesNotMatchRegularExpression($dialogPattern($resourceDialogId), $default->getContent());

        foreach ([
            ['dialog' => 'edit-environment-settings-'.$environment->id, 'id' => $environmentSettingsDialogId],
            ['dialog' => 'edit-deployment-controls-'.$environment->id, 'id' => $deploymentControlsDialogId],
            ['dialog' => 'add-environment', 'id' => 'add-environment-dialog'],
            ['dialog' => 'add-variable-'.$environment->id, 'id' => $variableDialogId],
            ['dialog' => 'add-process-'.$environment->id, 'id' => $processDialogId],
            ['dialog' => 'add-resource-'.$environment->id, 'id' => $resourceDialogId],
        ] as $dialog) {
            $this->assertMatchesRegularExpression(
                $dialogPattern($dialog['id']),
                $this->actingAs($developer)->get(route('projects.show', ['project' => $project, 'dialog' => $dialog['dialog']]))
                    ->assertSuccessful()->getContent(),
            );
        }

        $addEnvironmentUrl = route('projects.show', ['project' => $project, 'dialog' => 'add-environment']);
        $environmentError = $this->actingAs($developer)->from($addEnvironmentUrl)->followingRedirects()->post(route('environments.store', $project), [
            '_environment_form' => 'add', 'name' => '', 'type' => 'staging', 'branch' => '',
            'is_protected' => '0', 'requires_deployment_approval' => '0',
        ])->assertSuccessful();
        $this->assertMatchesRegularExpression($dialogPattern('add-environment-dialog'), $environmentError->getContent());
        $environmentError->assertSee('The name field is required.');

        $variableUrl = route('projects.show', ['project' => $project, 'dialog' => 'add-variable-'.$environment->id]);
        $variableError = $this->actingAs($developer)->from($variableUrl)->followingRedirects()->post(route('environments.variables.store', $environment), [
            '_environment_id' => $environment->id, '_environment_panel' => 'variables',
            'key' => 'not-valid', 'value' => 'private-value', 'scope' => 'runtime', 'is_secret' => '1',
        ])->assertSuccessful();
        $this->assertMatchesRegularExpression($dialogPattern($variableDialogId), $variableError->getContent());
        $variableError->assertSee('The key format is invalid.')->assertDontSee('private-value');

        $processUrl = route('projects.show', ['project' => $project, 'dialog' => 'add-process-'.$environment->id]);
        $processError = $this->actingAs($developer)->from($processUrl)->followingRedirects()->post(route('environments.processes.store', $environment), [
            '_environment_id' => $environment->id, '_environment_panel' => 'processes',
            'name' => '', 'type' => 'worker', 'command' => 'php artisan queue:work',
            'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => '1',
        ])->assertSuccessful();
        $this->assertMatchesRegularExpression($dialogPattern($processDialogId), $processError->getContent());
        $processError->assertSee('The name field is required.');

        $resourceUrl = route('projects.show', ['project' => $project, 'dialog' => 'add-resource-'.$environment->id]);
        $resourceError = $this->actingAs($developer)->from($resourceUrl)->followingRedirects()->post(route('environments.resources.store', $environment), [
            '_environment_id' => $environment->id, '_environment_panel' => 'resources',
            'name' => '', 'type' => 'mysql', 'is_managed' => '0',
            'variables' => 'DB_PASSWORD=private-resource-value',
        ])->assertSuccessful();
        $this->assertMatchesRegularExpression($dialogPattern($resourceDialogId), $resourceError->getContent());
        $resourceError->assertSee('The name field is required.')->assertSee('private-resource-value');

        $this->assertSame(2, $project->environments()->count());
        $this->assertDatabaseCount('environment_variables', 0);
        $this->assertDatabaseCount('environment_processes', 0);
        $this->assertDatabaseCount('environment_resources', 0);
    }

    private function workspaceProject(): array
    {
        $owner = User::factory()->create();
        $developer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($developer, ['role' => 'developer']);
        $developer->update(['current_organization_id' => $organization->id]);
        $project = $organization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Storefront',
            'slug' => 'storefront',
        ]);
        $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'is_protected' => true, 'requires_deployment_approval' => true,
        ]);
        $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'develop',
        ]);

        return [$owner, $developer, $project];
    }

    private function environmentPayload(string $name = 'QA'): array
    {
        return [
            'name' => $name,
            'type' => 'staging',
            'branch' => 'develop',
            'is_protected' => '0',
            'requires_deployment_approval' => '0',
            'minimum_replicas' => 1,
            'maximum_replicas' => 1,
            'hibernate_after_minutes' => null,
        ];
    }
}
