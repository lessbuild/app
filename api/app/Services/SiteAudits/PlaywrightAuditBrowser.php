<?php

declare(strict_types=1);

namespace App\Services\SiteAudits;

use App\Contracts\SiteAudits\AuditBrowser;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Drives `resources/site-audit/runner.mjs`, a Playwright Chromium, one JSON command per line. */
final class PlaywrightAuditBrowser implements AuditBrowser
{
    /**
     * The running Node process, started on the first command.
     *
     * @var Process|null
     */
    private ?Process $process = null;

    /**
     * The process's standard input.
     *
     * @var InputStream|null
     */
    private ?InputStream $input = null;

    /**
     * Output read but not yet split into lines.
     *
     * @var string
     */
    private string $buffer = '';

    /**
     * The next command's id.
     *
     * @var int
     */
    private int $nextId = 1;

    /**
     * Start a fresh browser session, on a desktop-sized screen or a phone.
     *
     * @param  bool  $mobile
     * @return void
     */
    public function start(bool $mobile = false): void
    {
        $this->send('start', ['mobile' => $mobile, 'userAgent' => (string) config('site_audits.user_agent')], 60);
    }

    /**
     * Open a public address.
     *
     * @param  string  $url
     * @return array{url: string, status?: int|null, title?: string, blocked?: string}
     */
    public function goto(string $url): array
    {
        /** @var array{url: string, status?: int|null, title?: string, blocked?: string} */
        return $this->send('goto', ['url' => $url], 60) + ['url' => $url];
    }

    /**
     * Describe the page on screen, saving a screenshot to an absolute path.
     *
     * @param  string  $screenshotPath
     * @return array{url: string, title: string, text: string, scrollY: int, pageHeight: int, viewportHeight: int,
     *     elements: list<array{n: int, tag: string, role: string, label: string, href?: string|null, box: array{x: int, y: int, width: int, height: int}}>}
     */
    public function observe(string $screenshotPath): array
    {
        /** @var array{url: string, title: string, text: string, scrollY: int, pageHeight: int, viewportHeight: int, elements: list<array{n: int, tag: string, role: string, label: string, href?: string|null, box: array{x: int, y: int, width: int, height: int}}>} */
        return $this->send('observe', ['screenshot' => $screenshotPath], 60);
    }

    /**
     * Do one thing on the page.
     *
     * @param  array{type: string, n?: int, text?: string, direction?: string}  $action
     * @return array{url: string, navigated?: bool, blocked?: string}
     */
    public function act(array $action): array
    {
        /** @var array{url: string, navigated?: bool, blocked?: string} */
        return $this->send('act', ['action' => $action], 60);
    }

    /**
     * Measure a page.
     *
     * @param  string  $url
     * @return array<string, mixed>
     */
    public function checks(string $url): array
    {
        return $this->send('checks', ['url' => $url], 150);
    }

    /**
     * Render HTML offline, without scripts, and save a screenshot.
     *
     * @param  string  $html
     * @param  string  $screenshotPath
     * @return void
     */
    public function render(string $html, string $screenshotPath): void
    {
        $this->send('render', ['html' => $html, 'screenshot' => $screenshotPath], 60);
    }

    /**
     * End the session and the browser.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->process?->isRunning()) {
            try {
                $this->send('close', [], 15);
            } catch (RuntimeException) {
                // The process is stopped below either way.
            }
            $this->input?->close();
            $this->process->stop(5);
        }
        $this->process = null;
        $this->input = null;
        $this->buffer = '';
    }

    /**
     * Send one command and wait for its answer.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $arguments
     * @param  int  $timeoutSeconds
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function send(string $command, array $arguments, int $timeoutSeconds): array
    {
        $this->boot();
        $id = $this->nextId++;
        $this->input?->write(json_encode(['id' => $id, 'cmd' => $command] + $arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $process = $this->process;
            if ($process === null) {
                break;
            }
            $this->buffer .= $process->getIncrementalOutput();
            while (($newline = strpos($this->buffer, "\n")) !== false) {
                $line = substr($this->buffer, 0, $newline);
                $this->buffer = substr($this->buffer, $newline + 1);
                $answer = json_decode($line, true);
                if (! is_array($answer) || ($answer['id'] ?? null) !== $id) {
                    continue;
                }
                if (($answer['ok'] ?? false) !== true) {
                    throw new RuntimeException(is_string($answer['error'] ?? null) ? $answer['error'] : __('The browser couldn’t do that.'));
                }
                unset($answer['id'], $answer['ok']);

                return $answer;
            }
            if (! $process->isRunning()) {
                throw new RuntimeException(__('The browser stopped unexpectedly: :error', ['error' => trim(mb_substr($process->getErrorOutput(), -300))]));
            }
            usleep(50_000);
        }
        $this->close();

        throw new RuntimeException(__('The browser took too long to answer.'));
    }

    /**
     * Start the Node process if it isn't running.
     *
     * @return void
     */
    private function boot(): void
    {
        if ($this->process?->isRunning()) {
            return;
        }
        $this->input = new InputStream;
        $browsers = config('site_audits.browsers_path');
        $this->process = new Process([(string) config('site_audits.node'), resource_path('site-audit/runner.mjs')], base_path(),
            is_string($browsers) && $browsers !== '' ? ['PLAYWRIGHT_BROWSERS_PATH' => $browsers] : null, $this->input, null);
        $this->process->start();
        $this->buffer = '';
    }

    /**
     * Stop the browser when the object goes away, so a failed job doesn't leave Chromium running.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->close();
    }
}
