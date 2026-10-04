<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

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

        $this->actingAs($owner)->getJson('/api/app/shell')->assertOk()->assertJsonPath('unseenChanges', 2);
        $this->actingAs($owner)->getJson('/api/app/whats-new')->assertOk()->assertJsonPath('entries.0.changes.0', 'Funnels arrived.');
        $this->assertNull($owner->refresh()->last_seen_changelog_at, 'Reading the dialog doesn’t mark it seen.');
        $this->actingAs($owner)->postJson('/api/app/whats-new/seen')->assertOk()->assertJsonPath('unseenChanges', 0);
        $this->assertSame('2026-09-29', $owner->refresh()->last_seen_changelog_at?->format('Y-m-d'));
        $this->actingAs($owner)->getJson('/api/app/shell')->assertOk()->assertJsonPath('unseenChanges', 0);

        config(['changelog' => [['date' => '2026-10-02', 'title' => 'Newer still', 'changes' => ['More.']], ...config('changelog')]]);
        $this->assertSame(1, Changelog::unseen('2026-09-29'));
        $this->actingAs($owner)->getJson('/api/app/shell')->assertJsonPath('unseenChanges', 1);
        $this->actingAs($owner)->getJson('/api/app/site/changelog')->assertOk()->assertJsonPath('entries.0.title', 'Newer still');
        $this->assertSame('2026-10-02', $owner->refresh()->last_seen_changelog_at?->format('Y-m-d'));
    }
}
