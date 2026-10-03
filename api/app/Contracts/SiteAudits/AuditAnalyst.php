<?php

declare(strict_types=1);

namespace App\Contracts\SiteAudits;

use RuntimeException;

/**
 * The judgement in an audit: it plays the visitor (choosing each action from a screenshot), rates the flows and writes
 * the findings, designs mock-ups of fixes, and suggests competitors. Every method throws RuntimeException on failure.
 */
interface AuditAnalyst
{
    /**
     * Decide the visitor's next action on a journey.
     *
     * @param  string  $goal  what the visitor is trying to do
     * @param  list<string>  $history  what they've done so far, oldest first
     * @param  array{url: string, title: string, text: string, scrollY: int, pageHeight: int, viewportHeight: int, elements: list<array<string, mixed>>}  $observation
     * @param  string  $screenshot  the screen as JPEG bytes
     * @return array{type: string, n?: int, text?: string, direction?: string, thought: string, summary?: string, friction?: list<string>}
     *                                                                                                                                     type is click, type, select, press_enter, scroll, back, finish or give_up; finish and give_up carry the summary and friction
     *
     * @throws RuntimeException
     */
    public function nextAction(string $goal, array $history, array $observation, string $screenshot): array;

    /**
     * Rate the judged categories for every site and write the findings for the audited site.
     *
     * @param  array<string, mixed>  $evidence  the sites, their journeys, measurements and key screenshots' references
     * @param  array<string, string>  $screenshots  reference => JPEG bytes, for the screens worth looking at
     * @return array{summary: string, sites: array<string, array<string, int>>, findings: list<array<string, mixed>>}
     *
     * @throws RuntimeException
     */
    public function assess(array $evidence, array $screenshots): array;

    /**
     * Write a self-contained HTML mock-up of one section with a finding fixed.
     *
     * @param  array<string, mixed>  $finding
     * @param  string  $screenshot  the section as it is today, as JPEG bytes
     * @param  string  $pageText
     * @return string
     *
     * @throws RuntimeException
     */
    public function mockup(array $finding, string $screenshot, string $pageText): string;

    /**
     * Suggest a site's closest competitors.
     *
     * @param  array{url: string, title: string, description: string, text: string}  $site
     * @param  list<array{title: string, url: string, description: string}>  $searchResults  may be empty
     * @return list<array{name: string, url: string, reason: string}>
     *
     * @throws RuntimeException
     */
    public function suggestCompetitors(array $site, array $searchResults): array;

    /**
     * Get the tokens used so far, for the run's cost record.
     *
     * @return array{input: int, output: int}
     */
    public function usage(): array;
}
