<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HelpCentreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that the help centre lists every guide under its group, with text to search.
     *
     * @return void
     */
    public function test_the_help_centre_lists_every_guide_by_group(): void
    {
        $page = $this->getJson('/api/app/help')->assertOk();
        foreach (config('help.groups') as $group) {
            $page->assertJsonFragment(['title' => $group['title']]);
        }
        foreach (array_keys(config('help.guides')) as $slug) {
            $page->assertJsonFragment(['slug' => $slug]);
        }
        $page->assertJsonPath('groups.0.guides.0.text', fn ($text): bool => is_string($text) && $text === mb_strtolower($text));
    }

    /**
     * Check that a guide shows its steps and the other guides in its group, and unknown guides are a 404.
     *
     * @return void
     */
    public function test_a_guide_shows_its_steps_and_related_guides(): void
    {
        $this->getJson('/api/app/help/create-a-server')->assertOk()->assertJsonPath('title', 'Create a server')->assertJsonFragment(['title' => 'Watch it come up'])
            ->assertJsonFragment(['slug' => 'add-a-website'])->assertJsonMissing(['slug' => 'previews']);
        $this->getJson('/api/app/help/nothing-here')->assertNotFound();
    }

    /**
     * Check that every guide belongs to a group that exists and has steps.
     *
     * @return void
     */
    public function test_every_guide_group_exists(): void
    {
        foreach (config('help.guides') as $slug => $guide) {
            $this->assertArrayHasKey($guide['group'], config('help.groups'), "{$slug} has an unknown group");
            $this->assertNotEmpty($guide['steps']);
        }
    }
}
