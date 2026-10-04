<?php

declare(strict_types=1);

namespace Tests\Feature\Api\App;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ShellTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The Next.js frame gets the same navigation the Blade pages show: the services, the service's sections inside a
     * project, the account links, and the person's language.
     */
    public function test_the_shell_describes_the_frame_around_a_service_page(): void
    {
        $project = Project::factory()->withServices(['audit'])->create(['name' => 'Storefront']);
        $owner = $this->ownerOf($project);
        $owner->forceFill(['current_account_id' => $project->account_id, 'locale' => 'fr'])->save();

        $shell = $this->actingAs($owner)->getJson("/api/app/shell?project={$project->id}&service=audit")->assertOk()->json();

        $this->assertSame('fr', $shell['locale']);
        $this->assertSame(['id' => $project->id, 'name' => 'Storefront', 'isSample' => false], $shell['project']);
        $this->assertContains('Audit', array_column($shell['primaryNav'], 'label'));
        $this->assertSame(["/projects/{$project->id}/audit"], array_column($shell['sectionNav'], 'url'));
        $this->assertSame(__(':service sections', ['service' => 'Audit'], 'fr'), $shell['sectionLabel']);
        $this->assertNotEmpty($shell['accountLinks']);

        $this->actingAs($owner)->getJson('/api/app/shell')->assertOk()->assertJsonPath('project', null)->assertJsonPath('sectionNav', []);
        $this->actingAs(User::factory()->create())->getJson("/api/app/shell?project={$project->id}")->assertNotFound();
    }
}
