<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_a_trimmed_escaped_note_with_metadata_only_activity(): void
    {
        [$owner, $build] = $this->build();
        $note = '<script>alert("note")</script> Incident INC-1042 approved by operations.';

        $this->actingAs($owner)->patch(route('builds.note.update', $build), [
            'operator_note' => "  \n{$note}\n  ",
        ])->assertSessionHas('success', 'Deployment note saved.');

        $this->assertSame($note, $build->fresh()->operator_note);
        $this->assertDatabaseHas('events', [
            'user_id' => $owner->id,
            'category' => 'deployment',
            'event' => 'Deployment note was updated.',
            'parentable_type' => Build::class,
            'parentable_id' => $build->id,
        ]);
        $noteEvent = $build->events()->where('event', 'Deployment note was updated.')->sole();
        $this->assertStringNotContainsString('INC-1042', $noteEvent->event);

        $this->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee($note)
            ->assertDontSee($note, false)
            ->assertSee(route('builds.note.update', $build))
            ->assertSee('maxlength="2000"', false)
            ->assertSee('Save note');
    }

    public function test_owner_can_clear_a_note_and_an_unchanged_note_does_not_duplicate_activity(): void
    {
        [$owner, $build] = $this->build();
        $build->update(['operator_note' => 'Existing handoff note']);

        $this->actingAs($owner)->patch(route('builds.note.update', $build), [
            'operator_note' => 'Existing handoff note',
        ])->assertSessionHas('info', 'Deployment note is unchanged.');
        $this->assertSame(0, $build->events()->where('event', 'like', 'Deployment note was %')->count());

        $this->patch(route('builds.note.update', $build), [
            'operator_note' => " \n ",
        ])->assertSessionHas('success', 'Deployment note cleared.');
        $this->assertNull($build->fresh()->operator_note);
        $this->assertDatabaseHas('events', [
            'user_id' => $owner->id,
            'event' => 'Deployment note was cleared.',
        ]);
    }

    public function test_note_is_bounded_and_other_users_cannot_change_it(): void
    {
        [$owner, $build] = $this->build();
        $build->update(['operator_note' => 'Original note']);

        $this->actingAs($owner)->patch(route('builds.note.update', $build), [
            'operator_note' => str_repeat('x', 2001),
        ])->assertSessionHasErrors(['operator_note'], errorBag: 'buildNote');
        $this->assertSame('Original note', $build->fresh()->operator_note);

        $this->actingAs(User::factory()->create())->patch(route('builds.note.update', $build), [
            'operator_note' => 'Unauthorized replacement',
        ])->assertForbidden();
        $this->assertSame('Original note', $build->fresh()->operator_note);
    }

    public function test_note_update_requires_authentication(): void
    {
        [, $build] = $this->build();

        $this->patch(route('builds.note.update', $build), [
            'operator_note' => 'Guest replacement',
        ])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($build->fresh()->operator_note);
    }

    /** @return array{User, Build} */
    private function build(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$owner, $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ])];
    }
}
