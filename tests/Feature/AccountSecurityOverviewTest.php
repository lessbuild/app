<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSecurityOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_shows_only_the_owners_five_latest_metadata_only_security_actions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $activity = app(ActivityRecorder::class);
        foreach (range(1, 7) as $position) {
            $event = $activity->recordAccount($owner, "Owner security action {$position}");
            $event->forceFill([
                'created_at' => now()->addSeconds($position),
                'updated_at' => now()->addSeconds($position),
            ])->save();
        }
        $activity->recordAccount($other, 'Foreign security action');

        $response = $this->actingAs($owner)->get(route('account.index'));

        $response
            ->assertSuccessful()
            ->assertViewHas('recentAccountEvents', function ($events): bool {
                $this->assertCount(5, $events);
                $this->assertSame([
                    'Owner security action 7',
                    'Owner security action 6',
                    'Owner security action 5',
                    'Owner security action 4',
                    'Owner security action 3',
                ], $events->pluck('event')->all());
                $this->assertTrue($events->every(fn ($event): bool => array_diff(
                    array_keys($event->getAttributes()),
                    ['id', 'event', 'category', 'created_at'],
                ) === []));

                return true;
            })
            ->assertSee('Recent security activity')
            ->assertSee('Owner security action 7')
            ->assertDontSee('Owner security action 2')
            ->assertDontSee('Owner security action 1')
            ->assertDontSee('Foreign security action')
            ->assertSee(route('activity.index', ['category' => 'account']));
    }

    public function test_security_overview_escapes_messages_and_has_a_useful_empty_state(): void
    {
        $owner = User::factory()->create();
        $unsafe = '<script>alert("security")</script>';
        app(ActivityRecorder::class)->recordAccount($owner, $unsafe);

        $this->actingAs($owner)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee($unsafe)
            ->assertDontSee($unsafe, false);

        $empty = User::factory()->create();
        $this->actingAs($empty)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('No security activity yet')
            ->assertSee('Account security changes will appear here');
    }

    public function test_unverified_owner_can_review_security_history_without_a_dead_audit_link(): void
    {
        $owner = User::factory()->unverified()->create();
        app(ActivityRecorder::class)->recordAccount($owner, 'Unverified owner changed security settings.');

        $this->actingAs($owner)->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('Unverified owner changed security settings.')
            ->assertDontSee('View full account audit')
            ->assertDontSee(route('activity.index', ['category' => 'account']));
    }
}
