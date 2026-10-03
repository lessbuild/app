<?php

declare(strict_types=1);

namespace App\Contracts\SiteAudits;

use RuntimeException;

/**
 * The headless browser an audit drives: it opens public pages, describes what's on screen, does what the simulated
 * visitor decides, measures pages and renders mock-ups. Methods throw RuntimeException when the browser refuses or fails.
 */
interface AuditBrowser
{
    /**
     * Start a fresh browser session, on a desktop-sized screen or a phone.
     *
     * @param  bool  $mobile
     * @return void
     *
     * @throws RuntimeException
     */
    public function start(bool $mobile = false): void;

    /**
     * Open a public address.
     *
     * @param  string  $url
     * @return array{url: string, status?: int|null, title?: string, blocked?: string}
     *
     * @throws RuntimeException
     */
    public function goto(string $url): array;

    /**
     * Describe the page on screen, saving a screenshot to an absolute path.
     *
     * @param  string  $screenshotPath
     * @return array{url: string, title: string, text: string, scrollY: int, pageHeight: int, viewportHeight: int,
     *     elements: list<array{n: int, tag: string, role: string, label: string, href?: string|null, box: array{x: int, y: int, width: int, height: int}}>}
     *
     * @throws RuntimeException
     */
    public function observe(string $screenshotPath): array;

    /**
     * Do one thing on the page: click, type, select, press_enter, scroll or back.
     *
     * @param  array{type: string, n?: int, text?: string, direction?: string}  $action
     * @return array{url: string, navigated?: bool, blocked?: string}
     *
     * @throws RuntimeException
     */
    public function act(array $action): array;

    /**
     * Measure a page: speed, accessibility, search basics and how it behaves on a phone.
     *
     * @param  string  $url
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function checks(string $url): array;

    /**
     * Render HTML offline, without scripts, and save a screenshot to an absolute path.
     *
     * @param  string  $html
     * @param  string  $screenshotPath
     * @return void
     *
     * @throws RuntimeException
     */
    public function render(string $html, string $screenshotPath): void;

    /**
     * End the session and the browser.
     *
     * @return void
     */
    public function close(): void;
}
