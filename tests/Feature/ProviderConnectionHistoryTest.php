<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderConnectionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_latest_twenty_checks_and_exports_safe_retained_history(): void
    {
        [$owner, $provider] = $this->provider('Owner');
        [, $foreignProvider] = $this->provider('Foreign');
        foreach (range(1, 21) as $position) {
            $provider->connectionChecks()->create([
                'successful' => $position % 2 === 0,
                'source' => $position % 2 === 0
                    ? ProviderConnectionCheck::SOURCE_AUTOMATIC
                    : ProviderConnectionCheck::SOURCE_MANUAL,
                'provider_type' => Provider::TYPE_GITHUB,
                'http_status' => $position % 2 === 0 ? 200 : 401,
                'duration_ms' => 100 + $position,
                'endpoint' => $position === 21 ? '=DANGEROUS()' : 'https://api.github.com/user',
                'error' => $position === 1
                    ? 'Old check hidden from the page'
                    : ($position === 21 ? "+formula\n<script>latest failure</script>" : null),
                'checked_at' => now()->subMinutes(22 - $position),
            ]);
        }
        $foreignProvider->connectionChecks()->create([
            'successful' => false,
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            'provider_type' => Provider::TYPE_GITLAB,
            'duration_ms' => 50,
            'endpoint' => 'https://gitlab.com/api/v4/user',
            'error' => 'Private provider result',
            'checked_at' => now(),
        ]);

        $page = $this->actingAs($owner)->get(route('providers.show', $provider));
        $page
            ->assertSuccessful()
            ->assertSee('Recent connection checks')
            ->assertSee('Manual')
            ->assertSee('Automatic')
            ->assertSee('HTTP 200')
            ->assertSee('HTTP 401')
            ->assertSee('121 ms')
            ->assertSee('<script>latest failure</script>')
            ->assertDontSee('<script>latest failure</script>', false)
            ->assertDontSee('Old check hidden from the page')
            ->assertDontSee('Private provider result')
            ->assertSee(route('providers.connection-checks.index', $provider))
            ->assertSee(route('providers.connection-checks.export', $provider));
        $this->assertSame(20, substr_count($page->getContent(), '<tr class="align-top">'));

        $export = $this->get(route('providers.connection-checks.export', $provider));
        $export
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=lessbuild-provider-'.$provider->id.'-connection-checks-',
            (string) $export->headers->get('content-disposition'),
        );
        $content = $export->streamedContent();
        $rows = $this->csvRows($content);
        $this->assertSame([
            'Check ID',
            'Result',
            'Source',
            'Provider type',
            'HTTP status',
            'Duration ms',
            'Endpoint',
            'Error',
            'Checked at',
        ], $rows[0]);
        $this->assertCount(22, $rows);
        $this->assertSame('failed', $rows[1][1]);
        $this->assertSame('manual', $rows[1][2]);
        $this->assertSame('401', $rows[1][4]);
        $this->assertSame('121', $rows[1][5]);
        $this->assertSame("'=DANGEROUS()", $rows[1][6]);
        $this->assertSame("'+formula\n<script>latest failure</script>", $rows[1][7]);
        $this->assertStringNotContainsString('Private provider result', $content);
    }

    public function test_history_routes_require_owner_and_history_cascades_on_force_delete(): void
    {
        [$owner, $provider] = $this->provider('Authorized');
        $provider->connectionChecks()->create([
            'successful' => true,
            'source' => ProviderConnectionCheck::SOURCE_MANUAL,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 200,
            'duration_ms' => 100,
            'endpoint' => 'https://api.github.com/user',
            'checked_at' => now(),
        ]);

        $this->get(route('providers.connection-checks.index', $provider))->assertRedirect(route('login'));
        $this->get(route('providers.connection-checks.export', $provider))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('providers.connection-checks.index', $provider))
            ->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->get(route('providers.connection-checks.export', $provider))
            ->assertForbidden();
        $this->actingAs($owner)
            ->get(route('providers.connection-checks.index', $provider))
            ->assertSuccessful();
        $this->actingAs($owner)
            ->get(route('providers.connection-checks.export', $provider))
            ->assertSuccessful();

        $provider->forceDelete();
        $this->assertDatabaseCount('provider_connection_checks', 0);
    }

    public function test_full_history_combines_filters_and_preserves_them_across_pagination(): void
    {
        [$owner, $provider] = $this->provider('Filtered');
        [, $foreignProvider] = $this->provider('Foreign filtered');
        foreach (range(1, 21) as $position) {
            $provider->connectionChecks()->create([
                'successful' => false,
                'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
                'provider_type' => Provider::TYPE_GITHUB,
                'http_status' => 401,
                'duration_ms' => 100 + $position,
                'endpoint' => "https://api.github.com/match-{$position}",
                'checked_at' => "2026-09-03 12:{$position}:00",
            ]);
        }
        $provider->connectionChecks()->create([
            'successful' => true,
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 200,
            'duration_ms' => 90,
            'endpoint' => 'https://api.github.com/healthy-excluded',
            'checked_at' => '2026-09-03 13:00:00',
        ]);
        $provider->connectionChecks()->create([
            'successful' => false,
            'source' => ProviderConnectionCheck::SOURCE_MANUAL,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 401,
            'duration_ms' => 91,
            'endpoint' => 'https://api.github.com/manual-excluded',
            'checked_at' => '2026-09-03 13:01:00',
        ]);
        $provider->connectionChecks()->create([
            'successful' => false,
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 401,
            'duration_ms' => 92,
            'endpoint' => 'https://api.github.com/date-excluded',
            'checked_at' => '2026-09-01 13:02:00',
        ]);
        $foreignProvider->connectionChecks()->create([
            'successful' => false,
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 401,
            'duration_ms' => 93,
            'endpoint' => 'https://api.github.com/private-check',
            'checked_at' => '2026-09-03 13:03:00',
        ]);
        $filters = [
            'result' => 'failed',
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-04',
        ];

        $page = $this->actingAs($owner)->get(route('providers.connection-checks.index', [$provider, ...$filters]));
        $page
            ->assertSuccessful()
            ->assertViewHas('filters', $filters)
            ->assertViewHas('connectionChecks', fn ($checks): bool => $checks->total() === 21 && $checks->count() === 20)
            ->assertSee('21 matching retained checks')
            ->assertSee('match-21')
            ->assertDontSee('match-1</td>', false)
            ->assertDontSee('healthy-excluded')
            ->assertDontSee('manual-excluded')
            ->assertDontSee('date-excluded')
            ->assertDontSee('private-check')
            ->assertSee('result=failed', false)
            ->assertSee('source=automatic', false)
            ->assertSee('date_from=2026-09-02', false)
            ->assertSee('date_to=2026-09-04', false);

        $this->get(route('providers.connection-checks.index', [$provider, ...$filters, 'page' => 2]))
            ->assertSuccessful()
            ->assertViewHas('connectionChecks', fn ($checks): bool => $checks->currentPage() === 2 && $checks->count() === 1)
            ->assertSee('match-1')
            ->assertDontSee('match-21');
    }

    public function test_invalid_history_filters_are_normalized_and_filtered_empty_state_is_explicit(): void
    {
        [$owner, $provider] = $this->provider('Normalized');
        $provider->connectionChecks()->create([
            'successful' => true,
            'source' => ProviderConnectionCheck::SOURCE_MANUAL,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 200,
            'duration_ms' => 100,
            'endpoint' => 'https://api.github.com/normalized-check',
            'checked_at' => '2026-09-03 10:00:00',
        ]);

        $this->actingAs($owner)->get(route('providers.connection-checks.index', [
            $provider,
            'result' => 'unknown',
            'source' => 'scheduler',
            'date_from' => '2026-02-30',
            'date_to' => 'not-a-date',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'result' => null,
                'source' => null,
                'date_from' => null,
                'date_to' => null,
            ])
            ->assertSee('normalized-check');

        $this->get(route('providers.connection-checks.index', [$provider, 'result' => 'failed']))
            ->assertSuccessful()
            ->assertSee('No connection checks match these filters.');
    }

    public function test_filtered_export_matches_the_view_and_is_spreadsheet_safe(): void
    {
        [$owner, $provider] = $this->provider('Export filtered');
        $provider->connectionChecks()->create([
            'successful' => false,
            'source' => ProviderConnectionCheck::SOURCE_MANUAL,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 503,
            'duration_ms' => 321,
            'endpoint' => '=DANGEROUS()',
            'error' => '+another formula',
            'checked_at' => '2026-09-03 10:00:00',
        ]);
        $provider->connectionChecks()->create([
            'successful' => true,
            'source' => ProviderConnectionCheck::SOURCE_MANUAL,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 200,
            'duration_ms' => 100,
            'endpoint' => 'https://api.github.com/healthy',
            'checked_at' => '2026-09-03 11:00:00',
        ]);
        $provider->connectionChecks()->create([
            'successful' => false,
            'source' => ProviderConnectionCheck::SOURCE_AUTOMATIC,
            'provider_type' => Provider::TYPE_GITHUB,
            'http_status' => 401,
            'duration_ms' => 100,
            'endpoint' => 'https://api.github.com/automatic',
            'checked_at' => '2026-09-03 12:00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('providers.connection-checks.export', [
            $provider,
            'result' => 'failed',
            'source' => ProviderConnectionCheck::SOURCE_MANUAL,
            'date_from' => '2026-09-03',
            'date_to' => '2026-09-03',
        ]));
        $response
            ->assertSuccessful()
            ->assertHeader('cache-control', 'no-store, private');
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('failed', $rows[1][1]);
        $this->assertSame('manual', $rows[1][2]);
        $this->assertSame("'=DANGEROUS()", $rows[1][6]);
        $this->assertSame("'+another formula", $rows[1][7]);
    }

    /** @return array{User, Provider} */
    private function provider(string $prefix): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => "{$prefix} GitHub",
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Provider',
        ]);

        return [$owner, $provider];
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
