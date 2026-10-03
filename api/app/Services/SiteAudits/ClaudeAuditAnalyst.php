<?php

declare(strict_types=1);

namespace App\Services\SiteAudits;

use Anthropic\Beta\Messages\BetaBase64ImageSource;
use Anthropic\Beta\Messages\BetaCacheControlEphemeral;
use Anthropic\Beta\Messages\BetaImageBlockParam;
use Anthropic\Beta\Messages\BetaJSONOutputFormat;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaMessageParam;
use Anthropic\Beta\Messages\BetaOutputConfig;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaTextBlockParam;
use Anthropic\Beta\Messages\BetaTool;
use Anthropic\Beta\Messages\BetaTool\InputSchema;
use Anthropic\Beta\Messages\BetaToolChoiceAuto;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Contracts\SiteAudits\AuditAnalyst;
use App\Enums\SiteAuditCategory;
use RuntimeException;

/**
 * Uses Claude (through Anthropic's PHP SDK) to play the visitor, judge the flows, design fixes and find competitors.
 * Requests opt into server-side refusal fallbacks, so a declined request is retried on the model's default fallback.
 */
final class ClaudeAuditAnalyst implements AuditAnalyst
{
    /**
     * The instructions for the simulated visitor. They never change between calls, so they're cached.
     *
     * @var string
     */
    private const VISITOR = <<<'PROMPT'
        You are playing a typical first-time visitor using a website in a real browser, so the site's owner can see how easy their site is to use.

        Each turn you get your goal, what you have done so far, the visible text of the page, a numbered list of the interactive elements on screen, and a screenshot. Answer by calling the browser_action tool once.

        Behave like an ordinary person, not an expert:
        - Use what is visible. Read headings and navigation. Scroll when the page clearly continues. Don't guess or type URLs.
        - Don't use the browser's search engine or leave the site, unless a link on the site sends you elsewhere.
        - When a form asks for details, use obviously fake test values: name "Alex Example", email "alex@example.com", company "Example Ltd", phone "+44 20 7946 0000". Never enter a password for a real account or any payment details, and never submit a final purchase, payment or sign-up.
        - Close cookie banners or pop-ups the way a person would, if they are in the way.

        Finish as soon as you have reached the goal (or the point the goal tells you to stop at). Give up when a real person would: after a few honest attempts that lead nowhere, or when the site blocks you. When you finish or give up, write a short first-person summary of how it went and list each specific moment of friction (confusing labels, missing information, slow or broken pages, too many steps, pop-ups in the way). Be candid and specific; this is feedback for the site's owner.
        PROMPT;

    /**
     * The instructions for rating the flows and writing the findings.
     *
     * @var string
     */
    private const ASSESSOR = <<<'PROMPT'
        You are a senior UX and conversion specialist reviewing an automated audit of a website and its competitors.

        You get, for each site, the journeys a simulated first-time visitor took (goal, outcome, steps, friction, summary) and browser measurements (speed, accessibility violations, search basics, phone layout), plus screenshots of key moments, each with a reference and the numbered elements that were on screen.

        1. Score each site from 0 to 100 on navigation (how easily visitors find their way), conversion (how well the flows lead to sign-up, contact or purchase), content (how clearly the site explains what it offers and to whom) and trust (proof, pricing transparency, contact details, polish). Compare sites with each other: the scores should reflect real differences.
        2. Write the findings for the audited site (key "site") only: the changes that would most improve it, most important first, at most 12. Each finding must be specific to this site, point at evidence (a screenshot reference and the numbers of the elements involved, when there is one), say plainly what's wrong and why it matters to visitors, and give a concrete recommendation. Where a competitor does it better, say how in competitor_note. Mark mockup true for the few findings (at most 3) where a picture of the improved section would help most, such as a confusing hero, navigation or form.
        3. Write a summary of three to five sentences: where the site stands against its competitors and the most valuable things to do first.

        Write in plain British English, addressed to the site's owner. Don't invent facts you can't see in the evidence.
        PROMPT;

    /**
     * Tokens used by this analyst so far.
     *
     * @var array{input: int, output: int}
     */
    private array $usage = ['input' => 0, 'output' => 0];

    /**
     * Create a new ClaudeAuditAnalyst instance.
     *
     * @param  Client  $client  Anthropic's API client, built with the platform's key.
     */
    public function __construct(private readonly Client $client) {}

    /**
     * Decide the visitor's next action on a journey.
     *
     * @param  string  $goal
     * @param  list<string>  $history
     * @param  array{url: string, title: string, text: string, scrollY: int, pageHeight: int, viewportHeight: int, elements: list<array<string, mixed>>}  $observation
     * @param  string  $screenshot
     * @return array{type: string, n?: int, text?: string, direction?: string, thought: string, summary?: string, friction?: list<string>}
     */
    public function nextAction(string $goal, array $history, array $observation, string $screenshot): array
    {
        $elements = [];
        foreach ($observation['elements'] as $element) {
            $elements[] = sprintf('[%d] %s "%s"', (int) ($element['n'] ?? 0), (string) ($element['role'] ?? ''), (string) ($element['label'] ?? ''));
        }
        $below = max(0, $observation['pageHeight'] - $observation['scrollY'] - $observation['viewportHeight']);
        $prompt = "Your goal: {$goal}\n\n"
            .'What you have done so far:'."\n".($history === [] ? '(nothing yet; you have just arrived)' : implode("\n", array_map(fn (string $line, int $index): string => ($index + 1).'. '.$line, $history, array_keys($history))))."\n\n"
            ."You are on: {$observation['url']} ({$observation['title']})\n"
            .($below > 0 ? "The page continues for about {$below} pixels below what you can see.\n" : "You can see the bottom of the page.\n")
            ."\nInteractive elements on screen:\n".($elements === [] ? '(none)' : implode("\n", $elements))
            ."\n\nVisible text (may be cut short):\n".$observation['text'];

        $message = $this->create(
            system: self::VISITOR,
            content: [$this->image($screenshot), BetaTextBlockParam::with(text: $prompt)],
            maxTokens: 8000,
            effort: 'medium',
            tools: [self::browserActionTool()],
        );
        foreach ($message->content as $block) {
            if ($block instanceof BetaToolUseBlock && $block->name === 'browser_action') {
                return $this->action((array) $block->input);
            }
        }

        return ['type' => 'give_up', 'thought' => 'No action was chosen.', 'summary' => $this->text($message), 'friction' => []];
    }

    /**
     * Rate the judged categories for every site and write the findings for the audited site.
     *
     * @param  array<string, mixed>  $evidence
     * @param  array<string, string>  $screenshots
     * @return array{summary: string, sites: array<string, array<string, int>>, findings: list<array<string, mixed>>}
     */
    public function assess(array $evidence, array $screenshots): array
    {
        $content = [];
        foreach ($screenshots as $reference => $jpeg) {
            $content[] = BetaTextBlockParam::with(text: "Screenshot {$reference}:");
            $content[] = $this->image($jpeg);
        }
        $content[] = BetaTextBlockParam::with(text: "The audit's evidence:\n".json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $judged = array_values(array_map(fn (SiteAuditCategory $category): string => $category->value, array_filter(SiteAuditCategory::cases(), fn (SiteAuditCategory $category): bool => ! $category->measured())));
        $score = ['type' => 'integer', 'minimum' => 0, 'maximum' => 100];
        $nullableString = ['type' => ['string', 'null']];
        $result = $this->structured(self::ASSESSOR, $content, 32000, 'high', [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'sites' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['key' => ['type' => 'string']] + array_fill_keys($judged, $score),
                    'required' => ['key', ...$judged],
                    'additionalProperties' => false,
                ]],
                'findings' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'enum' => array_map(fn (SiteAuditCategory $category): string => $category->value, SiteAuditCategory::cases())],
                        'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        'effort' => ['type' => 'string', 'enum' => ['small', 'medium', 'large']],
                        'title' => ['type' => 'string'],
                        'detail' => ['type' => 'string'],
                        'recommendation' => ['type' => 'string'],
                        'page_url' => $nullableString,
                        'screenshot' => $nullableString,
                        'elements' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'competitor_note' => $nullableString,
                        'mockup' => ['type' => 'boolean'],
                    ],
                    'required' => ['category', 'severity', 'effort', 'title', 'detail', 'recommendation', 'page_url', 'screenshot', 'elements', 'competitor_note', 'mockup'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['summary', 'sites', 'findings'],
            'additionalProperties' => false,
        ]);

        $sites = [];
        foreach ((array) ($result['sites'] ?? []) as $site) {
            if (is_array($site) && is_string($site['key'] ?? null)) {
                $sites[$site['key']] = array_map(fn (mixed $value): int => max(0, min(100, (int) $value)), array_intersect_key($site, array_flip($judged)));
            }
        }

        return [
            'summary' => (string) ($result['summary'] ?? ''),
            'sites' => $sites,
            'findings' => array_values(array_filter((array) ($result['findings'] ?? []), is_array(...))),
        ];
    }

    /**
     * Write a self-contained HTML mock-up of one section with a finding fixed.
     *
     * @param  array<string, mixed>  $finding
     * @param  string  $screenshot
     * @param  string  $pageText
     * @return string
     */
    public function mockup(array $finding, string $screenshot, string $pageText): string
    {
        $system = 'You are a product designer. You redesign one section of a web page to fix a usability problem, as a self-contained HTML mock-up that will be rendered at 1280 pixels wide. '
            .'Match the existing look closely (colours, type, spacing, imagery as simple shapes) so the owner can see the change, and change only what the fix needs. '
            .'Use one HTML document with inline CSS in a style element. No scripts, no external fonts, images or other resources. Use the page\'s real wording where it still fits.';
        $prompt = "The problem: {$finding['title']}\n".(string) ($finding['detail'] ?? '')."\n\nThe recommendation: ".(string) ($finding['recommendation'] ?? '')
            ."\n\nThe page's text, for reference:\n".mb_substr($pageText, 0, 3000)."\n\nThe screenshot shows the section as it is today. Return the improved section.";
        $result = $this->structured($system, [$this->image($screenshot), BetaTextBlockParam::with(text: $prompt)], 16000, 'medium', [
            'type' => 'object',
            'properties' => ['html' => ['type' => 'string']],
            'required' => ['html'],
            'additionalProperties' => false,
        ]);

        return (string) ($result['html'] ?? '');
    }

    /**
     * Suggest a site's closest competitors.
     *
     * @param  array{url: string, title: string, description: string, text: string}  $site
     * @param  list<array{title: string, url: string, description: string}>  $searchResults
     * @return list<array{name: string, url: string, reason: string}>
     */
    public function suggestCompetitors(array $site, array $searchResults): array
    {
        $system = 'You identify a website\'s closest direct competitors: other companies a visitor would compare it with before choosing. '
            .'Prefer well-known, currently operating companies with their own website. Give each competitor\'s home page address. Never include the site itself.';
        $prompt = "The site: {$site['url']}\nTitle: {$site['title']}\nDescription: {$site['description']}\n\nWhat it says:\n".mb_substr($site['text'], 0, 3000)
            .($searchResults === [] ? '' : "\n\nWeb search results that may include competitors:\n".json_encode($searchResults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            ."\n\nSuggest up to five competitors, closest first, each with a one-sentence reason.";
        $result = $this->structured($system, [BetaTextBlockParam::with(text: $prompt)], 8000, 'medium', [
            'type' => 'object',
            'properties' => ['competitors' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string'], 'url' => ['type' => 'string'], 'reason' => ['type' => 'string']],
                'required' => ['name', 'url', 'reason'],
                'additionalProperties' => false,
            ]]],
            'required' => ['competitors'],
            'additionalProperties' => false,
        ]);
        $competitors = [];
        foreach ((array) ($result['competitors'] ?? []) as $competitor) {
            if (is_array($competitor) && is_string($competitor['url'] ?? null) && is_string($competitor['name'] ?? null)) {
                $competitors[] = ['name' => mb_substr($competitor['name'], 0, 120), 'url' => $competitor['url'], 'reason' => mb_substr((string) ($competitor['reason'] ?? ''), 0, 500)];
            }
        }

        return array_slice($competitors, 0, 5);
    }

    /**
     * Get the tokens used so far.
     *
     * @return array{input: int, output: int}
     */
    public function usage(): array
    {
        return $this->usage;
    }

    /**
     * Describe the browser_action tool the visitor answers with. Strict, so the input always matches the schema.
     *
     * @return BetaTool
     */
    private static function browserActionTool(): BetaTool
    {
        // Strict tools need a closed schema; fromArray keeps additionalProperties, which with() has no parameter for.
        $schema = InputSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'thought' => ['type' => 'string', 'description' => 'One sentence: what you see and why you are doing this, in the first person.'],
                'action' => ['type' => 'string', 'enum' => ['click', 'type', 'select', 'press_enter', 'scroll', 'back', 'finish', 'give_up']],
                'element' => ['type' => ['integer', 'null'], 'description' => 'The element number, for click, type, select and press_enter.'],
                'text' => ['type' => ['string', 'null']],
                'direction' => ['type' => ['string', 'null'], 'enum' => ['up', 'down', null]],
                'summary' => ['type' => ['string', 'null'], 'description' => 'For finish and give_up: how the journey went, in two or three sentences.'],
                'friction' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For finish and give_up: each moment that slowed you down. Empty otherwise.'],
            ],
            'required' => ['thought', 'action', 'element', 'text', 'direction', 'summary', 'friction'],
            'additionalProperties' => false,
        ]);

        return BetaTool::with(
            inputSchema: $schema,
            name: 'browser_action',
            description: 'Do one thing in the browser, or end the journey. Use element numbers from the list. For type and select, text is what to enter or the option to choose; for scroll, direction is up or down. Use finish when the goal is reached and give_up when a real visitor would stop; both need summary and friction.',
            strict: true,
        );
    }

    /**
     * Turn the tool's input into the action the browser takes.
     *
     * @param  array<array-key, mixed>  $input
     * @return array{type: string, n?: int, text?: string, direction?: string, thought: string, summary?: string, friction?: list<string>}
     */
    private function action(array $input): array
    {
        $type = in_array($input['action'] ?? null, ['click', 'type', 'select', 'press_enter', 'scroll', 'back', 'finish', 'give_up'], true) ? (string) $input['action'] : 'give_up';
        $action = ['type' => $type, 'thought' => mb_substr((string) ($input['thought'] ?? ''), 0, 500)];
        if (is_int($input['element'] ?? null)) {
            $action['n'] = $input['element'];
        }
        if (is_string($input['text'] ?? null)) {
            $action['text'] = mb_substr($input['text'], 0, 200);
        }
        if (in_array($input['direction'] ?? null, ['up', 'down'], true)) {
            $action['direction'] = (string) $input['direction'];
        }
        if ($type === 'finish' || $type === 'give_up') {
            $action['summary'] = mb_substr((string) ($input['summary'] ?? ''), 0, 1000);
            $action['friction'] = array_values(array_map(fn (mixed $line): string => mb_substr((string) $line, 0, 300), array_filter((array) ($input['friction'] ?? []), is_string(...))));
        }

        return $action;
    }

    /**
     * Ask for an answer matching a JSON schema and decode it.
     *
     * @param  string  $system
     * @param  list<BetaTextBlockParam|BetaImageBlockParam>  $content
     * @param  int  $maxTokens
     * @param  'low'|'medium'|'high'  $effort
     * @param  array<string, mixed>  $schema
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException
     */
    private function structured(string $system, array $content, int $maxTokens, string $effort, array $schema): array
    {
        $message = $this->create($system, $content, $maxTokens, $effort, format: BetaJSONOutputFormat::with(schema: $schema));
        $decoded = json_decode($this->text($message), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(__('Claude’s answer couldn’t be read.'));
        }

        return $decoded;
    }

    /**
     * Send one Messages request, with the instructions cached, and count its tokens.
     *
     * @param  string  $system
     * @param  list<BetaTextBlockParam|BetaImageBlockParam>  $content
     * @param  int  $maxTokens
     * @param  'low'|'medium'|'high'  $effort
     * @param  list<BetaTool>  $tools
     * @param  BetaJSONOutputFormat|null  $format
     * @return BetaMessage
     *
     * @throws RuntimeException
     */
    private function create(string $system, array $content, int $maxTokens, string $effort, array $tools = [], ?BetaJSONOutputFormat $format = null): BetaMessage
    {
        try {
            // Refusal fallbacks: if the model declines a page, the request is retried on its default fallback model.
            $message = $this->client->beta->messages->create(
                model: (string) config('site_audits.model'),
                maxTokens: $maxTokens,
                system: [BetaTextBlockParam::with(text: $system, cacheControl: BetaCacheControlEphemeral::with())],
                messages: [BetaMessageParam::with(content: $content, role: 'user')],
                tools: $tools === [] ? null : $tools,
                toolChoice: $tools === [] ? null : BetaToolChoiceAuto::with(),
                outputConfig: BetaOutputConfig::with(effort: $effort, format: $format),
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (APIException $exception) {
            throw new RuntimeException(__('Claude couldn’t answer: :error', ['error' => mb_substr($exception->getMessage(), 0, 300)]), 0, $exception);
        }
        $this->usage['input'] += $message->usage->inputTokens + (int) $message->usage->cacheReadInputTokens + (int) $message->usage->cacheCreationInputTokens;
        $this->usage['output'] += $message->usage->outputTokens;
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException(__('Claude declined to review this page.'));
        }
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException(__('Claude’s answer was too long and was cut off.'));
        }

        return $message;
    }

    /**
     * Get a JPEG as an image content block.
     *
     * @param  string  $jpeg
     * @return BetaImageBlockParam
     */
    private function image(string $jpeg): BetaImageBlockParam
    {
        return BetaImageBlockParam::with(source: BetaBase64ImageSource::with(data: base64_encode($jpeg), mediaType: 'image/jpeg'));
    }

    /**
     * Join a message's text blocks.
     *
     * @param  BetaMessage  $message
     * @return string
     */
    private function text(BetaMessage $message): string
    {
        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        return $text;
    }
}
