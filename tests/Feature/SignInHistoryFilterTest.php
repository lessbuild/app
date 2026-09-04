<?php

namespace Tests\Feature;

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignInHistoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_history_combines_filters_and_preserves_them_across_pagination(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        foreach (range(1, 26) as $position) {
            $this->signIn($owner, [
                'method' => SignInEvent::METHOD_PASSWORD,
                'ip_address' => '192.0.2.'.(100 + $position),
                'signed_in_at' => "2026-09-03 12:{$position}:00",
            ]);
        }
        $this->signIn($owner, [
            'method' => 'github',
            'ip_address' => '198.51.100.20',
            'signed_in_at' => '2026-09-03 13:00:00',
        ]);
        $this->signIn($owner, [
            'method' => SignInEvent::METHOD_PASSWORD,
            'ip_address' => '203.0.113.20',
            'signed_in_at' => '2026-09-01 13:00:00',
        ]);
        $this->signIn($other, [
            'method' => SignInEvent::METHOD_PASSWORD,
            'ip_address' => '203.0.113.99',
            'user_agent' => 'Foreign raw agent marker',
            'signed_in_at' => '2026-09-03 14:00:00',
        ]);
        $filters = [
            'method' => SignInEvent::METHOD_PASSWORD,
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-04',
        ];

        $page = $this->actingAs($owner)->get(route('account.sign-ins.index', $filters));
        $page
            ->assertSuccessful()
            ->assertViewHas('filters', $filters)
            ->assertViewHas('signIns', fn ($signIns): bool => $signIns->total() === 26 && $signIns->count() === 25)
            ->assertSee('26 matching sign-ins')
            ->assertSee('192.0.2.126')
            ->assertDontSee('192.0.2.101')
            ->assertDontSee('198.51.100.20')
            ->assertDontSee('203.0.113.20')
            ->assertDontSee('203.0.113.99')
            ->assertDontSee('Foreign raw agent marker')
            ->assertSee('method=password', false)
            ->assertSee('date_from=2026-09-02', false)
            ->assertSee('date_to=2026-09-04', false);

        $this->get(route('account.sign-ins.index', [...$filters, 'page' => 2]))
            ->assertSuccessful()
            ->assertViewHas('signIns', fn ($signIns): bool => $signIns->currentPage() === 2 && $signIns->count() === 1)
            ->assertSee('192.0.2.101')
            ->assertDontSee('192.0.2.126');
    }

    public function test_invalid_filters_are_normalized_and_filtered_empty_state_is_explicit(): void
    {
        $owner = User::factory()->create();
        $this->signIn($owner, ['ip_address' => '192.0.2.44']);

        $this->actingAs($owner)->get(route('account.sign-ins.index', [
            'method' => 'magic-link',
            'date_from' => '2026-02-30',
            'date_to' => 'not-a-date',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'method' => null,
                'date_from' => null,
                'date_to' => null,
            ])
            ->assertSee('192.0.2.44');

        $this->get(route('account.sign-ins.index', ['method' => 'bitbucket']))
            ->assertSuccessful()
            ->assertSee('No sign-ins match these filters.');
    }

    public function test_filtered_export_matches_the_view_and_never_exposes_raw_agents(): void
    {
        $owner = User::factory()->create();
        $this->signIn($owner, [
            'method' => 'github',
            'ip_address' => '198.51.100.21',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1 raw-github-agent-secret',
            'signed_in_at' => '2026-09-03 10:00:00',
        ]);
        $this->signIn($owner, [
            'method' => SignInEvent::METHOD_PASSWORD,
            'ip_address' => '192.0.2.11',
            'user_agent' => 'Mozilla/5.0 (Macintosh) Chrome/140.0 raw-password-agent-secret',
            'signed_in_at' => '2026-09-03 11:00:00',
        ]);
        $this->signIn($owner, [
            'method' => 'github',
            'ip_address' => '198.51.100.22',
            'user_agent' => 'raw-date-excluded-agent-secret',
            'signed_in_at' => '2026-09-01 10:00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('account.sign-ins.export', [
            'method' => 'github',
            'date_from' => '2026-09-03',
            'date_to' => '2026-09-03',
        ]));
        $response
            ->assertSuccessful()
            ->assertHeader('cache-control', 'no-store, private');
        $content = $response->streamedContent();
        $rows = $this->csvRows($content);
        $this->assertCount(2, $rows);
        $this->assertSame('GitHub', $rows[1][1]);
        $this->assertSame('Safari on iPhone', $rows[1][2]);
        $this->assertSame('198.51.100.21', $rows[1][3]);
        $this->assertStringNotContainsString('raw-github-agent-secret', $content);
        $this->assertStringNotContainsString('raw-password-agent-secret', $content);
        $this->assertStringNotContainsString('raw-date-excluded-agent-secret', $content);
        $this->assertStringNotContainsString('192.0.2.11', $content);
        $this->assertStringNotContainsString('198.51.100.22', $content);
    }

    /** @param array<string, mixed> $attributes */
    private function signIn(User $user, array $attributes = []): SignInEvent
    {
        return $user->signIns()->create(array_merge([
            'method' => SignInEvent::METHOD_PASSWORD,
            'ip_address' => '192.0.2.70',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/140.0',
            'signed_in_at' => now(),
        ], $attributes));
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
