<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HelpCentreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_help_centre_lists_every_guide_by_group(): void
    {
        $page = $this->get('/help')->assertOk()->assertHeader('Cache-Control', 'max-age=300, public')->assertSee('How can we help?')->assertSee('data-help-search', false);
        foreach (config('help.groups') as $group) {
            $page->assertSee($group['title']);
        }
        foreach (array_keys(config('help.guides')) as $slug) {
            $page->assertSee(route('help.guide', $slug));
        }
    }

    public function test_a_guide_shows_its_steps_and_related_guides(): void
    {
        $this->get('/help/create-a-server')->assertOk()->assertSee('Create a server')->assertSee('Watch it come up')->assertSee(route('help.guide', 'add-a-website'))->assertDontSee(route('help.guide', 'previews'));
        $this->get('/help/nothing-here')->assertNotFound();
    }

    public function test_every_guide_group_exists_and_signed_in_people_can_reach_help(): void
    {
        foreach (config('help.guides') as $slug => $guide) {
            $this->assertArrayHasKey($guide['group'], config('help.groups'), "{$slug} has an unknown group");
            $this->assertNotEmpty($guide['steps']);
        }
        $user = User::factory()->create();
        Account::factory()->withMember($user)->create();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee(route('help'));
    }
}
