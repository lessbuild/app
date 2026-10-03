<?php

declare(strict_types=1);

namespace Tests\Unit\SiteAudits;

use App\Enums\SiteAuditOutcome;
use App\Support\SiteAudits\AuditScores;
use PHPUnit\Framework\TestCase;

final class AuditScoresTest extends TestCase
{
    /**
     * A fast, tidy page scores full marks; a slow, broken one loses points in every measured category.
     */
    public function test_measured_scores(): void
    {
        $good = AuditScores::measured([
            'performance' => ['ttfbMs' => 200, 'lcpMs' => 1500, 'cls' => 0.01, 'transferBytes' => 800_000],
            'seo' => ['title' => 'Bikes for city riders — Shop', 'description' => str_repeat('Good bikes. ', 8), 'h1Count' => 1, 'canonical' => '/', 'lang' => 'en',
                'viewport' => 'width=device-width', 'ogImage' => 'x', 'structuredData' => 1, 'images' => 3, 'imagesWithoutAlt' => 0],
            'accessibility' => [],
            'mobile' => ['horizontalOverflow' => false, 'smallTapTargets' => 0, 'tapTargets' => 30, 'smallText' => 0],
        ]);
        $this->assertSame(['performance' => 100, 'accessibility' => 100, 'seo' => 100, 'mobile' => 100], $good);

        $bad = AuditScores::measured([
            'performance' => ['ttfbMs' => 3500, 'lcpMs' => 7000, 'cls' => 0.4, 'transferBytes' => 9_000_000],
            'seo' => ['title' => '', 'description' => null, 'h1Count' => 0, 'images' => 4, 'imagesWithoutAlt' => 4, 'robots' => 'noindex'],
            'accessibility' => [['impact' => 'critical'], ['impact' => 'serious'], ['impact' => 'moderate'], ['impact' => 'minor']],
            'mobile' => ['horizontalOverflow' => true, 'smallTapTargets' => 20, 'tapTargets' => 20, 'smallText' => 30],
        ]);
        $this->assertSame(['performance' => 0, 'accessibility' => 68, 'seo' => 0, 'mobile' => 0], $bad);
    }

    /**
     * Reaching the goal counts most; extra steps and friction take points off.
     */
    public function test_journey_scores(): void
    {
        $this->assertSame(100, AuditScores::journey(SiteAuditOutcome::Succeeded, 3, 0));
        $this->assertSame(89, AuditScores::journey(SiteAuditOutcome::Succeeded, 6, 1));
        $this->assertSame(56, AuditScores::journey(SiteAuditOutcome::Struggled, 7, 1));
        $this->assertSame(0, AuditScores::journey(SiteAuditOutcome::Failed, 12, 3));
    }

    /**
     * The overall score weights navigation and conversion three times as much as search or trust.
     */
    public function test_overall_score_is_weighted(): void
    {
        $this->assertSame(100, AuditScores::overall(['navigation' => 100, 'conversion' => 100]));
        $this->assertSame(75, AuditScores::overall(['navigation' => 100, 'seo' => 0]));
        $this->assertSame(0, AuditScores::overall([]));
    }
}
