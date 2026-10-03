<?php

declare(strict_types=1);

namespace App\Services\SiteAudits;

use App\Actions\Billing\RecordUsage;
use App\Contracts\SiteAudits\AuditAnalyst;
use App\Contracts\SiteAudits\AuditBrowser;
use App\Enums\SiteAuditCategory;
use App\Enums\SiteAuditOutcome;
use App\Enums\SiteAuditStatus;
use App\Models\SiteAuditCompetitor;
use App\Models\SiteAuditFinding;
use App\Models\SiteAuditJourney;
use App\Models\SiteAuditRun;
use App\Models\SiteAuditStep;
use App\Services\Billing\Entitlements;
use App\Support\SiteAudits\AuditScores;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs an audit: every journey on the site and each confirmed competitor, the browser's measurements of each start
 * page, Claude's assessment of it all, and the findings with their screenshots and mock-ups.
 */
final class SiteAuditRunner
{
    /**
     * The private disk holding screenshots and mock-ups.
     *
     * @var Filesystem
     */
    private Filesystem $disk;

    /**
     * Create a new SiteAuditRunner instance.
     *
     * @param  AuditBrowser  $browser  The headless browser.
     * @param  AuditAnalyst  $analyst  Claude, as the visitor and the reviewer.
     * @param  Entitlements  $entitlements  The plan's limits on competitors and pages.
     * @param  RecordUsage  $usage  Counts finished runs on the audits meter.
     */
    public function __construct(
        private readonly AuditBrowser $browser,
        private readonly AuditAnalyst $analyst,
        private readonly Entitlements $entitlements,
        private readonly RecordUsage $usage,
    ) {
        $this->disk = Storage::disk('local');
    }

    /**
     * Run a queued audit run to the end, marking it done or failed.
     *
     * @param  SiteAuditRun  $run
     * @return void
     */
    public function run(SiteAuditRun $run): void
    {
        if ($run->status !== SiteAuditStatus::Queued) {
            return;
        }
        $run->forceFill(['status' => SiteAuditStatus::Running, 'started_at' => now()])->save();
        $this->disk->makeDirectory($run->storageDirectory());

        try {
            $this->audit($run);
            $usage = $this->analyst->usage();
            $run->forceFill(['status' => SiteAuditStatus::Done, 'finished_at' => now(), 'input_tokens' => $usage['input'], 'output_tokens' => $usage['output']])->save();
            $this->usage->handle($run->audit->project->account_id, SiteAuditUsage::METER, 1);
        } catch (Throwable $exception) {
            report($exception);
            $usage = $this->analyst->usage();
            $run->forceFill([
                'status' => SiteAuditStatus::Failed, 'finished_at' => now(), 'input_tokens' => $usage['input'], 'output_tokens' => $usage['output'],
                'error' => $exception instanceof RuntimeException ? mb_substr($exception->getMessage(), 0, 1000) : __('The audit stopped because of an internal error.'),
            ])->save();
        } finally {
            $this->browser->close();
        }
    }

    /**
     * Take the journeys on every site, then assess them and save the scores and findings.
     *
     * @param  SiteAuditRun  $run
     * @return void
     */
    private function audit(SiteAuditRun $run): void
    {
        $audit = $run->audit;
        $limits = $this->entitlements->for($audit->project->account);
        $competitors = $audit->competitors()->where('status', 'confirmed')->limit(max(0, $limits->limit('audit.competitors') ?? 5))->get();
        $pageLimit = max(5, $limits->limit('audit.pages') ?? 100);

        $sites = [['key' => 'site', 'name' => $audit->name, 'url' => $audit->url, 'competitor' => null]];
        foreach ($competitors as $competitor) {
            $sites[] = ['key' => 'competitor:'.$competitor->id, 'name' => $competitor->name, 'url' => $competitor->url, 'competitor' => $competitor];
        }

        $measured = [];
        $checks = [];
        $unreachable = [];
        foreach ($sites as $site) {
            $this->browser->start();
            try {
                $checks[$site['key']] = $this->browser->checks($site['url']);
                $measured[$site['key']] = isset($checks[$site['key']]['blocked']) ? [] : AuditScores::measured($checks[$site['key']]);
            } catch (RuntimeException $exception) {
                if ($site['competitor'] === null) {
                    throw $exception;
                }
                // A competitor that can't be reached is left out of the comparison rather than failing the run.
                $unreachable[$site['key']] = $exception->getMessage();

                continue;
            }
            $visited = [];
            foreach ($audit->journeys as $journey) {
                $this->journey($run, $site['url'], $site['competitor'], (string) $journey['key'], (string) $journey['goal'], $pageLimit, $visited);
            }
            $run->forceFill(['pages_visited' => $run->pages_visited + count($visited)])->save();
        }

        $this->assess($run, array_values(array_filter($sites, fn (array $site): bool => ! isset($unreachable[$site['key']]))), $checks, $measured);
    }

    /**
     * Take one journey on one site, recording each step.
     *
     * @param  SiteAuditRun  $run
     * @param  string  $url
     * @param  SiteAuditCompetitor|null  $competitor
     * @param  string  $goalKey
     * @param  string  $goal
     * @param  int  $pageLimit  distinct pages the site may be visited on, across its journeys
     * @param  array<string, true>  $visited  the pages visited on this site so far
     * @return SiteAuditJourney
     */
    private function journey(SiteAuditRun $run, string $url, ?SiteAuditCompetitor $competitor, string $goalKey, string $goal, int $pageLimit, array &$visited): SiteAuditJourney
    {
        $journey = new SiteAuditJourney;
        $journey->forceFill([
            'site_audit_run_id' => $run->id, 'site_audit_competitor_id' => $competitor?->id, 'site_url' => $url,
            'goal_key' => $goalKey, 'goal' => mb_substr($goal, 0, 300), 'outcome' => SiteAuditOutcome::Failed,
        ])->save();

        $started = microtime(true);
        $history = [];
        $outcome = SiteAuditOutcome::Failed;
        $summary = null;
        $friction = [];
        $this->browser->start();
        $opened = $this->browser->goto($url);
        if (isset($opened['blocked'])) {
            $summary = __('The site’s robots.txt asks automated visitors not to open this page, so the audit didn’t.');
        } else {
            $visited[$opened['url']] = true;
            for ($position = 1; $position <= (int) config('site_audits.max_steps'); $position++) {
                $stepStarted = microtime(true);
                $path = $run->storageDirectory().'/'.Str::random(20).'.jpg';
                $observation = $this->browser->observe($this->disk->path($path));
                $visited[$observation['url']] = true;
                $action = $this->analyst->nextAction($goal, $history, $observation, (string) $this->disk->get($path));

                $target = isset($action['n']) ? collect($observation['elements'])->firstWhere('n', $action['n']) : null;
                $step = new SiteAuditStep;
                $step->forceFill([
                    'site_audit_journey_id' => $journey->id, 'position' => $position, 'url' => mb_substr($observation['url'], 0, 2048),
                    'action' => array_filter(['type' => $action['type'], 'label' => is_array($target) ? (string) $target['label'] : null, 'text' => $action['text'] ?? null, 'direction' => $action['direction'] ?? null], fn (mixed $value): bool => $value !== null),
                    'thought' => $action['thought'], 'screenshot_path' => $path, 'boxes' => is_array($target) ? [$target['box']] : null,
                    'elements' => array_map(fn (array $element): array => ['n' => $element['n'], 'label' => $element['label'], 'role' => $element['role'], 'box' => $element['box']], $observation['elements']),
                ])->save();

                if ($action['type'] === 'finish' || $action['type'] === 'give_up') {
                    $summary = $action['summary'] ?? null;
                    $friction = $action['friction'] ?? [];
                    $outcome = $action['type'] === 'give_up' ? SiteAuditOutcome::Failed : (count($friction) > 2 ? SiteAuditOutcome::Struggled : SiteAuditOutcome::Succeeded);
                    $step->forceFill(['duration_ms' => (int) ((microtime(true) - $stepStarted) * 1000)])->save();
                    break;
                }

                $described = $this->describe($action, $target);
                try {
                    $result = $this->browser->act(array_intersect_key($action, array_flip(['type', 'n', 'text', 'direction'])));
                    $history[] = $described.(isset($result['blocked']) ? ' (the site asks automated visitors not to open that page, so it stayed closed)' : '').' → now on '.$result['url'];
                    $visited[$result['url']] = true;
                } catch (RuntimeException $exception) {
                    $history[] = $described.' → that didn’t work: '.$exception->getMessage();
                }
                $step->forceFill(['duration_ms' => (int) ((microtime(true) - $stepStarted) * 1000)])->save();

                if (count($visited) >= $pageLimit) {
                    $summary = __('The audit stopped this journey because it reached your plan’s page limit for the site.');
                    $outcome = SiteAuditOutcome::Struggled;
                    break;
                }
            }
            if ($summary === null) {
                $summary = __('The visitor didn’t reach the goal within :steps steps.', ['steps' => (int) config('site_audits.max_steps')]);
                $friction[] = __('Too many steps to reach the goal.');
            }
        }

        $steps = $journey->steps()->count();
        $journey->forceFill([
            'outcome' => $outcome, 'steps_count' => $steps, 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'summary' => $summary, 'friction' => $friction, 'score' => AuditScores::journey($outcome, $steps, count($friction)),
        ])->save();

        return $journey;
    }

    /**
     * Describe an action for the visitor's history, such as `Clicked link "Pricing"`.
     *
     * @param  array{type: string, n?: int, text?: string, direction?: string, thought: string}  $action
     * @param  mixed  $target  the element acted on, if any
     * @return string
     */
    private function describe(array $action, mixed $target): string
    {
        $label = is_array($target) ? sprintf('%s "%s"', (string) ($target['role'] ?? ''), (string) ($target['label'] ?? '')) : '';

        return match ($action['type']) {
            'click' => "Clicked {$label}",
            'type' => 'Typed "'.($action['text'] ?? '')."\" into {$label}",
            'select' => 'Chose "'.($action['text'] ?? '')."\" in {$label}",
            'press_enter' => "Pressed Enter in {$label}",
            'scroll' => 'Scrolled '.($action['direction'] ?? 'down'),
            'back' => 'Went back',
            default => $action['type'],
        };
    }

    /**
     * Have Claude assess the evidence, then save the scores and findings, with mock-ups of the most useful fixes.
     *
     * @param  SiteAuditRun  $run
     * @param  list<array{key: string, name: string, url: string, competitor: SiteAuditCompetitor|null}>  $sites
     * @param  array<string, array<string, mixed>>  $checks
     * @param  array<string, array<string, int>>  $measured
     * @return void
     */
    private function assess(SiteAuditRun $run, array $sites, array $checks, array $measured): void
    {
        $journeys = $run->journeys()->with('steps')->get();
        $evidence = ['sites' => []];
        $screenshots = [];
        $steps = [];
        foreach ($sites as $site) {
            $competitorId = $site['competitor']?->id;
            $ownJourneys = $journeys->filter(fn (SiteAuditJourney $journey): bool => $journey->site_audit_competitor_id === $competitorId);
            $entry = ['key' => $site['key'], 'name' => $site['name'], 'url' => $site['url'], 'measurements' => $this->compactChecks($checks[$site['key']] ?? []), 'measured_scores' => $measured[$site['key']] ?? [], 'journeys' => []];
            foreach ($ownJourneys as $journey) {
                $entry['journeys'][] = [
                    'goal' => $journey->goal, 'outcome' => $journey->outcome->value, 'steps' => $journey->steps_count, 'seconds' => (int) round($journey->duration_ms / 1000),
                    'summary' => $journey->summary, 'friction' => $journey->friction ?? [],
                    'path' => $journey->steps->map(fn (SiteAuditStep $step): string => "[s{$step->id}] {$step->url}: ".($step->action['type'] ?? '').' '.($step->action['label'] ?? '').' — '.$step->thought)->all(),
                ];
                // The first and last screens of each journey on the site, and the first screen of each competitor.
                $keyScreens = $site['competitor'] === null ? [$journey->steps->first(), $journey->steps->last()] : ($journey->is($ownJourneys->first()) ? [$journey->steps->first()] : []);
                foreach (array_filter($keyScreens) as $step) {
                    if ($step->screenshot_path !== null && $this->disk->exists($step->screenshot_path) && count($screenshots) < 14) {
                        $screenshots['s'.$step->id] = (string) $this->disk->get($step->screenshot_path);
                        $steps['s'.$step->id] = $step;
                        $entry['screens']['s'.$step->id] = array_map(fn (array $element): string => '['.$element['n'].'] '.$element['role'].' "'.$element['label'].'"', $step->elements ?? []);
                    }
                }
            }
            $evidence['sites'][] = $entry;
        }

        $assessment = $this->analyst->assess($evidence, $screenshots);

        $scores = [];
        foreach ($sites as $site) {
            $competitorId = $site['competitor']?->id;
            $journeyScores = $journeys->filter(fn (SiteAuditJourney $journey): bool => $journey->site_audit_competitor_id === $competitorId)->pluck('score')->filter(fn (mixed $score): bool => $score !== null);
            $journeyAverage = $journeyScores->isEmpty() ? null : (int) round((float) $journeyScores->avg());
            $judged = $assessment['sites'][$site['key']] ?? [];
            $categories = $measured[$site['key']] ?? [];
            foreach ([SiteAuditCategory::Navigation, SiteAuditCategory::Conversion] as $category) {
                $values = array_filter([$judged[$category->value] ?? null, $journeyAverage], fn (?int $value): bool => $value !== null);
                if ($values !== []) {
                    $categories[$category->value] = (int) round(array_sum($values) / count($values));
                }
            }
            foreach ([SiteAuditCategory::Content, SiteAuditCategory::Trust] as $category) {
                if (isset($judged[$category->value])) {
                    $categories[$category->value] = $judged[$category->value];
                }
            }
            $scores[$site['key']] = ['name' => $site['name'], 'url' => $site['url'], 'score' => AuditScores::overall($categories), 'categories' => $categories];
        }
        $run->forceFill(['scores' => $scores, 'score' => $scores['site']['score'] ?? null, 'summary' => mb_substr($assessment['summary'], 0, 5000)])->save();

        $mockups = 0;
        foreach (array_slice($assessment['findings'], 0, 12) as $item) {
            $category = SiteAuditCategory::tryFrom((string) ($item['category'] ?? '')) ?? SiteAuditCategory::Content;
            $step = is_string($item['screenshot'] ?? null) ? ($steps[$item['screenshot']] ?? null) : null;
            $wanted = array_map(intval(...), (array) ($item['elements'] ?? []));
            $boxes = $step === null ? null : array_values(array_map(fn (array $element): array => $element['box'], array_filter($step->elements ?? [], fn (array $element): bool => in_array($element['n'], $wanted, true))));

            $finding = new SiteAuditFinding;
            $finding->forceFill([
                'site_audit_run_id' => $run->id, 'project_id' => $run->project_id, 'category' => $category,
                'severity' => in_array($item['severity'] ?? null, ['high', 'medium', 'low'], true) ? $item['severity'] : 'medium',
                'effort' => in_array($item['effort'] ?? null, ['small', 'medium', 'large'], true) ? $item['effort'] : 'medium',
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 255), 'detail' => mb_substr((string) ($item['detail'] ?? ''), 0, 5000),
                'recommendation' => mb_substr((string) ($item['recommendation'] ?? ''), 0, 5000),
                'page_url' => is_string($item['page_url'] ?? null) ? mb_substr($item['page_url'], 0, 2048) : $step?->url,
                'screenshot_path' => $step?->screenshot_path, 'boxes' => $boxes === [] ? null : $boxes,
                'competitor_note' => is_string($item['competitor_note'] ?? null) ? mb_substr($item['competitor_note'], 0, 2000) : null,
            ])->save();

            if (($item['mockup'] ?? false) === true && $step?->screenshot_path !== null && $mockups < (int) config('site_audits.max_mockups')) {
                $mockups++;
                $this->mockup($run, $finding, $item, $step);
            }
        }
    }

    /**
     * Render a mock-up of a finding's fix and attach it. A failed mock-up leaves the finding without one.
     *
     * @param  SiteAuditRun  $run
     * @param  SiteAuditFinding  $finding
     * @param  array<string, mixed>  $item
     * @param  SiteAuditStep  $step
     * @return void
     */
    private function mockup(SiteAuditRun $run, SiteAuditFinding $finding, array $item, SiteAuditStep $step): void
    {
        try {
            $html = $this->analyst->mockup($item, (string) $this->disk->get((string) $step->screenshot_path), $this->stepText($step));
            if (trim($html) === '') {
                return;
            }
            $path = $run->storageDirectory().'/mockup-'.$finding->id.'.png';
            $this->browser->start();
            $this->browser->render($html, $this->disk->path($path));
            $finding->forceFill(['mockup_path' => $path])->save();
        } catch (RuntimeException $exception) {
            report($exception);
        }
    }

    /**
     * Get the on-screen element labels of a step, as stand-in page text for a mock-up.
     *
     * @param  SiteAuditStep  $step
     * @return string
     */
    private function stepText(SiteAuditStep $step): string
    {
        return implode("\n", array_map(fn (array $element): string => (string) $element['label'], $step->elements ?? []));
    }

    /**
     * Keep the parts of a page's checks Claude needs, leaving out the noise.
     *
     * @param  array<string, mixed>  $checks
     * @return array<string, mixed>
     */
    private function compactChecks(array $checks): array
    {
        if (isset($checks['error']) || isset($checks['blocked'])) {
            return array_intersect_key($checks, array_flip(['error', 'blocked']));
        }
        $accessibility = array_map(fn (array $violation): string => ($violation['impact'] ?? '').': '.($violation['help'] ?? '').' ('.($violation['nodes'] ?? 0).')', array_filter((array) ($checks['accessibility'] ?? []), is_array(...)));

        return ['performance' => $checks['performance'] ?? [], 'seo' => $checks['seo'] ?? [], 'mobile' => $checks['mobile'] ?? [], 'accessibility' => array_values($accessibility)];
    }
}
