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

    public function test_history_export_requires_owner_and_history_cascades_on_force_delete(): void
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

        $this->get(route('providers.connection-checks.export', $provider))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('providers.connection-checks.export', $provider))
            ->assertForbidden();
        $this->actingAs($owner)
            ->get(route('providers.connection-checks.export', $provider))
            ->assertSuccessful();

        $provider->forceDelete();
        $this->assertDatabaseCount('provider_connection_checks', 0);
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
