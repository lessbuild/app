<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Support\Changelog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WhatsNewTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that the app shows a "What's new" dot until the person has seen the latest changelog entries, either by
     * dismissing the dialog or by opening the changelog.
     *
     * @return void
     */
    public function test_whats_new_is_flagged_until_seen(): void
    {
        config(['changelog' => [
            ['date' => '2026-09-29', 'title' => 'Newest', 'changes' => ['Funnels arrived.']],
            ['date' => '2026-09-20', 'title' => 'Older', 'changes' => ['Something older.']],
        ]]);
        $owner = $this->ownerOf(Project::factory()->create());

        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('What’s new (2 new)')->assertSee('Funnels arrived.')->assertSee(route('roadmap'));
        $this->actingAs($owner)->from('/dashboard')->post('/whats-new/seen')->assertRedirect('/dashboard');
        $this->assertSame('2026-09-29', $owner->refresh()->last_seen_changelog_at?->format('Y-m-d'));
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertDontSee('What’s new (')->assertSee('What’s new');

        config(['changelog' => [['date' => '2026-10-02', 'title' => 'Newer still', 'changes' => ['More.']], ...config('changelog')]]);
        $this->assertSame(1, Changelog::unseen('2026-09-29'));
        $this->actingAs($owner)->get('/dashboard')->assertSee('What’s new (1 new)');
        $this->actingAs($owner)->get('/changelog')->assertOk()->assertSee('Newer still');
        $this->assertSame('2026-10-02', $owner->refresh()->last_seen_changelog_at?->format('Y-m-d'));
    }
}
