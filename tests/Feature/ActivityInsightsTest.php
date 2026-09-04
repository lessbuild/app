<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_recognized_activity_groups_without_foreign_events(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $latestAt = now()->subMinute();
        $this->event($owner, 'deployment', 'Owner deployment event', now()->subDays(8));
        $this->event($owner, 'website', 'Owner website event', now()->subDays(7));
        $this->event($owner, 'server', 'Owner server event', now()->subDays(6));
        $this->event($owner, 'provider', 'Owner provider event', now()->subDays(5));
        $this->event($owner, 'command', 'Owner command event', now()->subDays(4));
        $this->event($owner, 'recipe', 'Owner recipe event', now()->subDays(4)->addHour());
        $this->event($owner, 'account', 'Owner account event', now()->subDays(3));
        $this->event($owner, 'general', 'Owner general event', now()->subDays(2));
        $this->event($owner, 'legacy', 'Owner legacy event', $latestAt);
        $this->event($other, 'deployment', 'Foreign private deployment event', now());

        $this->actingAs($owner)->get(route('activity.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 9
                && $metrics['deployments'] === 1
                && $metrics['infrastructure'] === 3
                && $metrics['commands'] === 1
                && $metrics['recipes'] === 1
                && $metrics['account'] === 1
                && $metrics['latest_at']->timestamp === $latestAt->timestamp)
            ->assertSee('Matching events')
            ->assertSee('Deployments')
            ->assertSee('Infrastructure')
            ->assertSee('Server commands')
            ->assertSee('Recipes')
            ->assertSee('Account security')
            ->assertSee('Latest matching event')
            ->assertDontSee('Foreign private deployment event');
    }

    public function test_metrics_apply_search_category_and_date_filters(): void
    {
        $owner = User::factory()->create();
        $matchingAt = now()->subDay();
        $matching = $this->event($owner, 'deployment', 'Searchable release completed', $matchingAt);
        $this->event($owner, 'deployment', 'Searchable release too old', now()->subDays(10));
        $this->event($owner, 'server', 'Searchable server completed', $matchingAt);
        $date = $matchingAt->format('Y-m-d');

        $this->actingAs($owner)->get(route('activity.index', [
            'search' => 'Searchable',
            'category' => 'deployment',
            'date_from' => $date,
            'date_to' => $date,
        ]))
            ->assertSuccessful()
            ->assertViewHas('events', fn ($events): bool => $events->count() === 1
                && $events->sole()->id === $matching->id)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['deployments'] === 1
                && $metrics['infrastructure'] === 0
                && $metrics['commands'] === 0
                && $metrics['recipes'] === 0
                && $metrics['account'] === 0
                && $metrics['latest_at']->timestamp === $matchingAt->timestamp);
    }

    public function test_empty_filtered_activity_has_explicit_zero_and_unknown_metrics(): void
    {
        $owner = User::factory()->create();
        $this->event($owner, 'account', 'Account event', now());

        $this->actingAs($owner)->get(route('activity.index', ['category' => 'deployment']))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'deployments' => 0,
                'infrastructure' => 0,
                'commands' => 0,
                'recipes' => 0,
                'account' => 0,
                'latest_at' => null,
            ])
            ->assertSee('Not available')
            ->assertSee('No matching event recorded.')
            ->assertSee('No activity matches these filters');
    }

    private function event(User $user, string $category, string $message, mixed $createdAt): Event
    {
        $event = $user->accountEvents()->create([
            'user_id' => $user->id,
            'category' => $category,
            'event' => $message,
        ]);
        $event->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $event->fresh();
    }
}
