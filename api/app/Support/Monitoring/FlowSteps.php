<?php

declare(strict_types=1);

namespace App\Support\Monitoring;

/**
 * Reads a multi-step check's steps from plain text. Steps are separated by a blank line. Each starts with a method and
 * URL (GET https://shop.example/login), followed by any of:
 *
 * - header Name: value
 * - form a=1&b=2 (sent as a form), or json {"a": 1}
 * - expect 200 "Text that must appear" (the status, the text, or both)
 * - extract name regex (the first group of the first match, used later as {{name}})
 */
final class FlowSteps
{
    /**
     * The most steps a check can have.
     *
     * @var int
     */
    public const MAX_STEPS = 10;

    /**
     * An example login flow, shown in the form.
     *
     * @var string
     */
    public const EXAMPLE = <<<'STEPS'
GET https://shop.example/login
expect 200 "Sign in"
extract token name="_token" value="([^"]+)"

POST https://shop.example/login
form email=monitor@example.com&password=…&_token={{token}}
expect 302

GET https://shop.example/account
expect 200 "Your orders"
STEPS;

    /**
     * Parse the steps, or return why they can't be read.
     *
     * @param  string  $text
     * @return array{steps: list<array{method: string, url: string, headers: array<string, string>, form: string|null, json: string|null, status: int|null, text: string|null, extract: array<string, string>}>, error: null}|array{steps: list<never>, error: string}
     */
    public static function parse(string $text): array
    {
        $blocks = preg_split("/\n\\s*\n/", trim(str_replace("\r\n", "\n", $text))) ?: [];
        $steps = [];
        foreach ($blocks as $index => $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
            if ($lines === []) {
                continue;
            }
            $number = $index + 1;
            if (preg_match('#^(GET|POST|PUT|PATCH|DELETE|HEAD)\s+(https?://\S+)$#i', $lines[0], $request) !== 1) {
                return self::error(__('Step :step must start with a method and a full URL, such as GET https://example.com/login.', ['step' => $number]));
            }
            $step = ['method' => strtoupper($request[1]), 'url' => $request[2], 'headers' => [], 'form' => null, 'json' => null, 'status' => null, 'text' => null, 'extract' => []];
            foreach (array_slice($lines, 1) as $line) {
                [$directive, $rest] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');
                switch (strtolower($directive)) {
                    case 'header':
                        if (preg_match('/^([A-Za-z0-9-]+):\s*(.+)$/', $rest, $header) !== 1) {
                            return self::error(__('Step :step: write headers as header Name: value.', ['step' => $number]));
                        }
                        $step['headers'][$header[1]] = $header[2];
                        break;
                    case 'form':
                        $step['form'] = $rest;
                        break;
                    case 'json':
                        if (! json_validate(preg_replace('/\{\{\w+\}\}/', '0', $rest) ?? '')) {
                            return self::error(__('Step :step: the json line isn’t valid JSON.', ['step' => $number]));
                        }
                        $step['json'] = $rest;
                        break;
                    case 'expect':
                        if (preg_match('/^(\d{3})?\s*(?:"(.*)")?$/', $rest, $expect) !== 1 || ($expect[1] ?? '') === '' && ($expect[2] ?? '') === '') {
                            return self::error(__('Step :step: write expect 200 "Some text" (the status, the text, or both).', ['step' => $number]));
                        }
                        $step['status'] = ($expect[1] ?? '') !== '' ? (int) $expect[1] : null;
                        $step['text'] = ($expect[2] ?? '') !== '' ? $expect[2] : null;
                        break;
                    case 'extract':
                        [$name, $pattern] = array_pad(preg_split('/\s+/', $rest, 2) ?: [], 2, '');
                        if (preg_match('/^\w{1,40}$/', $name) !== 1 || $pattern === '' || @preg_match('~'.str_replace('~', '\~', $pattern).'~', '') === false) {
                            return self::error(__('Step :step: write extract name pattern, with a valid regular expression.', ['step' => $number]));
                        }
                        $step['extract'][$name] = $pattern;
                        break;
                    default:
                        return self::error(__('Step :step: :line isn’t something a step understands (header, form, json, expect or extract).', ['step' => $number, 'line' => mb_substr($directive, 0, 30)]));
                }
            }
            $steps[] = $step;
        }
        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            return self::error(__('Write one to :count steps.', ['count' => self::MAX_STEPS]));
        }

        return ['steps' => $steps, 'error' => null];
    }

    /**
     * Wrap an error message.
     *
     * @param  string  $message
     * @return array{steps: list<never>, error: string}
     */
    private static function error(string $message): array
    {
        return ['steps' => [], 'error' => $message];
    }
}
