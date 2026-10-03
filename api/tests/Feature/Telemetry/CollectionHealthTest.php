<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\Project;
use App\Queries\Telemetry\CollectionHealthQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CollectionHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_health_classifies_each_environment_without_claiming_uptime(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create(['name' => 'Payments API']);
        $receiving = Environment::factory()->for($project)->create(['name' => 'Receiving', 'slug' => 'receiving', 'telemetry_event_count' => 12, 'telemetry_last_received_at' => now()->subMinutes(5)]);
        $stale = Environment::factory()->for($project)->create(['name' => 'Stale', 'slug' => 'stale', 'telemetry_last_received_at' => now()->subHours(2)]);
        $awaiting = Environment::factory()->for($project)->create(['name' => 'Awaiting', 'slug' => 'awaiting']);
        IngestToken::factory()->for($receiving)->withSecret('receiving-key')->create();
        IngestToken::factory()->for($stale)->withSecret('stale-key')->create();
        IngestToken::factory()->for($awaiting)->withSecret('awaiting-key')->create();
        IngestToken::factory()->for($awaiting)->revoked()->create();
        $foreign = Environment::factory()->create(['telemetry_last_received_at' => now()]);
        IngestToken::factory()->for($foreign)->create();

        $summary = app(CollectionHealthQuery::class)->handle($project);

        // Production (created with every project) has no key yet.
        $this->assertSame(4, $summary['total']);
        $this->assertSame(['receiving' => 1, 'stale' => 1, 'awaiting' => 1, 'no_token' => 1], $summary['counts']);
        $states = $summary['environments']->mapWithKeys(static fn (array $item): array => [$item['environment']->slug => $item['state']->value]);
        $this->assertSame(['awaiting' => 'awaiting', 'production' => 'no_token', 'receiving' => 'receiving', 'stale' => 'stale'], $states->sortKeys()->all());
    }
}
