<?php

declare(strict_types=1);

namespace Tests\Unit\SiteAudits;

use Anthropic\Client;
use App\Services\SiteAudits\ClaudeAuditAnalyst;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

final class ClaudeAuditAnalystTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    /**
     * The visitor's request carries the screenshot, the cached instructions, a strict browser_action tool, the effort
     * and refusal fallbacks; the tool call it gets back becomes the browser's next action, and tokens are counted.
     */
    public function test_the_visitor_sends_the_screen_and_reads_back_one_action(): void
    {
        config(['site_audits.model' => 'claude-opus-5']);
        $analyst = $this->analyst([$this->message([
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'browser_action', 'input' => [
                'thought' => 'Pricing is in the menu.', 'action' => 'click', 'element' => 3, 'text' => null, 'direction' => null, 'summary' => null, 'friction' => [],
            ]],
        ], 'tool_use')]);

        $action = $analyst->nextAction('Find the price.', ['Clicked link "Home" → now on https://example.com/'], [
            'url' => 'https://example.com/', 'title' => 'Example', 'text' => 'Welcome', 'scrollY' => 0, 'pageHeight' => 2000, 'viewportHeight' => 800,
            'elements' => [['n' => 3, 'role' => 'link', 'label' => 'Pricing']],
        ], 'jpeg-bytes');

        $this->assertSame(['type' => 'click', 'thought' => 'Pricing is in the menu.', 'n' => 3], $action);
        $this->assertSame(['input' => 150, 'output' => 40], $analyst->usage());

        $request = $this->sent[0]['request'];
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('claude-opus-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertSame(['type' => 'auto'], $body['tool_choice']);
        $this->assertSame('medium', $body['output_config']['effort']);
        $this->assertEquals(['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode('jpeg-bytes')], $body['messages'][0]['content'][0]['source']);
        $this->assertStringContainsString('[3] link "Pricing"', $body['messages'][0]['content'][1]['text']);
        $this->assertStringContainsString('1. Clicked link "Home"', $body['messages'][0]['content'][1]['text']);
        $tool = $body['tools'][0];
        $this->assertSame('browser_action', $tool['name']);
        $this->assertTrue($tool['strict']);
        $this->assertFalse($tool['input_schema']['additionalProperties']);
        $this->assertContains('friction', $tool['input_schema']['required']);
    }

    /**
     * Finishing carries the visitor's summary and friction; no tool call at all counts as giving up.
     */
    public function test_finishing_and_answering_without_a_tool(): void
    {
        $analyst = $this->analyst([
            $this->message([['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'browser_action', 'input' => [
                'thought' => 'Done.', 'action' => 'finish', 'element' => null, 'text' => null, 'direction' => null, 'summary' => 'Easy.', 'friction' => ['A slow page.'],
            ]]], 'tool_use'),
            $this->message([['type' => 'text', 'text' => 'I am not sure what to do.']], 'end_turn'),
        ]);
        $observation = ['url' => 'https://example.com/', 'title' => 'Example', 'text' => '', 'scrollY' => 0, 'pageHeight' => 800, 'viewportHeight' => 800, 'elements' => []];

        $this->assertSame(['type' => 'finish', 'thought' => 'Done.', 'summary' => 'Easy.', 'friction' => ['A slow page.']], $analyst->nextAction('Goal', [], $observation, 'x'));
        $this->assertSame('give_up', $analyst->nextAction('Goal', [], $observation, 'x')['type']);
    }

    /**
     * The assessment asks for JSON matching a schema at high effort, and keys the judged scores by site.
     */
    public function test_the_assessment_is_structured_output(): void
    {
        $analyst = $this->analyst([$this->message([['type' => 'text', 'text' => json_encode([
            'summary' => 'Behind on conversion.',
            'sites' => [['key' => 'site', 'navigation' => 80, 'conversion' => 140, 'content' => 70, 'trust' => 60]],
            'findings' => [['category' => 'conversion', 'severity' => 'high', 'effort' => 'small', 'title' => 'T', 'detail' => 'D', 'recommendation' => 'R',
                'page_url' => null, 'screenshot' => 's1', 'elements' => [2], 'competitor_note' => null, 'mockup' => true]],
        ])]], 'end_turn')]);

        $result = $analyst->assess(['sites' => []], ['s1' => 'jpeg']);

        $this->assertSame(['navigation' => 80, 'conversion' => 100, 'content' => 70, 'trust' => 60], $result['sites']['site']);
        $this->assertSame('T', $result['findings'][0]['title']);
        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('high', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame('Screenshot s1:', $body['messages'][0]['content'][0]['text']);
        $this->assertSame('image', $body['messages'][0]['content'][1]['type']);
    }

    /**
     * A refusal stops the step with a readable error.
     */
    public function test_a_refusal_is_an_error(): void
    {
        $analyst = $this->analyst([$this->message([], 'refusal')]);

        $this->expectExceptionMessage('Claude declined to review this page.');
        $analyst->suggestCompetitors(['url' => 'https://example.com/', 'title' => 'E', 'description' => '', 'text' => ''], []);
    }

    /**
     * Build an analyst whose client answers with the given responses and records each request.
     *
     * @param  list<Response>  $responses
     */
    private function analyst(array $responses): ClaudeAuditAnalyst
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return new ClaudeAuditAnalyst(new Client(apiKey: 'test-key', requestOptions: ['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0]));
    }

    /**
     * Build a Messages API response.
     *
     * @param  list<array<string, mixed>>  $content
     */
    private function message(array $content, string $stopReason): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5', 'content' => $content,
            'stop_reason' => $stopReason, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40, 'cache_read_input_tokens' => 50, 'cache_creation_input_tokens' => 0],
        ]));
    }
}
