<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\SiteAudits\AuditBrowser;
use RuntimeException;

/** A browser that shows every site as a home page with a Pricing link and a Sign up button, and records what it was asked. */
final class FakeAuditBrowser implements AuditBrowser
{
    /** @var list<string> */
    public array $log = [];

    public string $url = '';

    /** @var list<string> URLs that fail to load */
    public array $failing = [];

    public function start(bool $mobile = false): void
    {
        $this->log[] = 'start';
    }

    public function goto(string $url): array
    {
        if (in_array($url, $this->failing, true)) {
            throw new RuntimeException('The site couldn’t be reached.');
        }
        $this->log[] = "goto {$url}";
        $this->url = $url;

        return ['url' => $url, 'status' => 200, 'title' => 'Home'];
    }

    public function observe(string $screenshotPath): array
    {
        file_put_contents($screenshotPath, 'jpeg-bytes');

        return [
            'url' => $this->url, 'title' => 'Home', 'text' => 'Welcome. Pricing. Sign up.', 'scrollY' => 0, 'pageHeight' => 2400, 'viewportHeight' => 800,
            'elements' => [
                ['n' => 1, 'tag' => 'a', 'role' => 'link', 'label' => 'Pricing', 'href' => '/pricing', 'box' => ['x' => 900, 'y' => 20, 'width' => 80, 'height' => 30]],
                ['n' => 2, 'tag' => 'button', 'role' => 'button', 'label' => 'Sign up', 'box' => ['x' => 1000, 'y' => 20, 'width' => 100, 'height' => 36]],
            ],
        ];
    }

    public function act(array $action): array
    {
        $this->log[] = 'act '.$action['type'].(isset($action['n']) ? ' '.$action['n'] : '');
        if ($action['type'] === 'click' && ($action['n'] ?? null) === 1) {
            $this->url = rtrim($this->url, '/').'/pricing';
        }

        return ['url' => $this->url, 'navigated' => true];
    }

    public function checks(string $url): array
    {
        if (in_array($url, $this->failing, true)) {
            throw new RuntimeException('The site couldn’t be reached.');
        }
        $this->log[] = "checks {$url}";

        return [
            'url' => $url, 'status' => 200,
            'performance' => ['ttfbMs' => 300, 'lcpMs' => 1800, 'loadMs' => 2000, 'cls' => 0.02, 'requests' => 30, 'transferBytes' => 900_000],
            'seo' => ['title' => 'Example — the best example', 'description' => str_repeat('A description of the example site. ', 3), 'h1Count' => 1, 'canonical' => $url, 'lang' => 'en', 'viewport' => 'width=device-width', 'robots' => null, 'ogImage' => 'x.png', 'structuredData' => 1, 'imagesWithoutAlt' => 0, 'images' => 4],
            'accessibility' => [['id' => 'color-contrast', 'impact' => 'serious', 'help' => 'Elements must have sufficient color contrast', 'nodes' => 3]],
            'mobile' => ['horizontalOverflow' => false, 'smallTapTargets' => 2, 'tapTargets' => 40, 'smallText' => 0],
        ];
    }

    public function render(string $html, string $screenshotPath): void
    {
        $this->log[] = 'render';
        file_put_contents($screenshotPath, 'png-bytes');
    }

    public function close(): void
    {
        $this->log[] = 'close';
    }
}
