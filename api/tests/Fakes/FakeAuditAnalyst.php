<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\SiteAudits\AuditAnalyst;

/** A visitor who clicks Pricing and then finishes, and a reviewer who writes one finding with a mock-up. */
final class FakeAuditAnalyst implements AuditAnalyst
{
    /** @var list<string> */
    public array $goals = [];

    /** @var array<string, mixed>|null */
    public ?array $evidence = null;

    /** @var list<array{name: string, url: string, reason: string}> */
    public array $competitors = [];

    public function nextAction(string $goal, array $history, array $observation, string $screenshot): array
    {
        $this->goals[] = $goal;
        if ($history === []) {
            return ['type' => 'click', 'n' => 1, 'thought' => 'The Pricing link is at the top.'];
        }

        return ['type' => 'finish', 'thought' => 'I found the prices.', 'summary' => 'Easy enough.', 'friction' => ['The prices were below a long banner.']];
    }

    public function assess(array $evidence, array $screenshots): array
    {
        $this->evidence = $evidence;
        $sites = [];
        foreach ($evidence['sites'] as $site) {
            $sites[$site['key']] = ['navigation' => 80, 'conversion' => $site['key'] === 'site' ? 60 : 85, 'content' => 70, 'trust' => 75];
        }
        $screenshot = array_key_first($screenshots);

        return [
            'summary' => 'The site is behind its competitor on conversion.',
            'sites' => $sites,
            'findings' => [
                ['category' => 'conversion', 'severity' => 'high', 'effort' => 'small', 'title' => 'The sign-up button is easy to miss', 'detail' => 'It is grey.',
                    'recommendation' => 'Make it the main colour.', 'page_url' => null, 'screenshot' => $screenshot, 'elements' => [2], 'competitor_note' => 'The competitor uses a bright button.', 'mockup' => true],
                ['category' => 'accessibility', 'severity' => 'low', 'effort' => 'small', 'title' => 'Low contrast text', 'detail' => 'Three elements.',
                    'recommendation' => 'Darken the text.', 'page_url' => null, 'screenshot' => null, 'elements' => [], 'competitor_note' => null, 'mockup' => false],
            ],
        ];
    }

    public function mockup(array $finding, string $screenshot, string $pageText): string
    {
        return '<button style="background:#2563eb">Sign up</button>';
    }

    public function suggestCompetitors(array $site, array $searchResults): array
    {
        return $this->competitors;
    }

    public function usage(): array
    {
        return ['input' => 1200, 'output' => 300];
    }
}
