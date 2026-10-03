<?php

declare(strict_types=1);

namespace App\Support\SiteAudits;

use App\Enums\SiteAuditCategory;
use App\Enums\SiteAuditOutcome;

/** Turns what the browser measured and how the journeys went into 0–100 scores. */
final class AuditScores
{
    /**
     * Score the measured categories (speed, accessibility, search, mobile) from a page's checks.
     *
     * @param  array<string, mixed>  $checks  the browser's `checks` answer
     * @return array<string, int> category value => score
     */
    public static function measured(array $checks): array
    {
        $performance = (array) ($checks['performance'] ?? []);
        $seo = (array) ($checks['seo'] ?? []);
        $mobile = (array) ($checks['mobile'] ?? []);
        $violations = array_filter((array) ($checks['accessibility'] ?? []), is_array(...));

        return [
            SiteAuditCategory::Performance->value => self::performance($performance),
            SiteAuditCategory::Accessibility->value => self::accessibility($violations),
            SiteAuditCategory::Seo->value => self::seo($seo),
            SiteAuditCategory::Mobile->value => self::mobile($mobile, $seo),
        ];
    }

    /**
     * Score a journey: reaching the goal counts most, then how few steps and how little friction it took.
     *
     * @param  SiteAuditOutcome  $outcome
     * @param  int  $steps
     * @param  int  $frictionCount
     * @return int
     */
    public static function journey(SiteAuditOutcome $outcome, int $steps, int $frictionCount): int
    {
        $base = match ($outcome) {
            SiteAuditOutcome::Succeeded => 100,
            SiteAuditOutcome::Struggled => 70,
            SiteAuditOutcome::Failed => 25,
        };
        $stepPenalty = max(0, $steps - 4) * 3;

        return self::clamp($base - $stepPenalty - $frictionCount * 5);
    }

    /**
     * Combine category scores into the overall score, weighting the flows people take most.
     *
     * @param  array<string, int>  $categories  category value => score
     * @return int
     */
    public static function overall(array $categories): int
    {
        $total = 0;
        $weights = 0;
        foreach ($categories as $key => $score) {
            $category = SiteAuditCategory::tryFrom($key);
            if ($category !== null) {
                $total += $score * $category->weight();
                $weights += $category->weight();
            }
        }

        return $weights === 0 ? 0 : self::clamp((int) round($total / $weights));
    }

    /**
     * Score speed from Largest Contentful Paint, time to first byte, layout shift and page weight.
     *
     * @param  array<array-key, mixed>  $performance
     * @return int
     */
    private static function performance(array $performance): int
    {
        $lcp = (int) ($performance['lcpMs'] ?? 0) ?: (int) ($performance['loadMs'] ?? 0);
        $score = 100;
        $score -= self::band($lcp, 2500, 4000, 6000, [0, 15, 30, 45]);
        $score -= self::band((int) ($performance['ttfbMs'] ?? 0), 800, 1800, 3000, [0, 10, 20, 25]);
        $score -= (float) ($performance['cls'] ?? 0) > 0.25 ? 15 : ((float) ($performance['cls'] ?? 0) > 0.1 ? 7 : 0);
        $score -= self::band((int) ($performance['transferBytes'] ?? 0), 1_500_000, 3_000_000, 6_000_000, [0, 5, 10, 15]);

        return self::clamp($score);
    }

    /**
     * Score accessibility from axe's WCAG A and AA violations, weighted by impact.
     *
     * @param  array<array-key, array<array-key, mixed>>  $violations
     * @return int
     */
    private static function accessibility(array $violations): int
    {
        $score = 100;
        foreach ($violations as $violation) {
            $score -= match ($violation['impact'] ?? null) {
                'critical' => 15,
                'serious' => 10,
                'moderate' => 5,
                default => 2,
            };
        }

        return self::clamp($score);
    }

    /**
     * Score search basics: title, description, one h1, canonical, language, alt text, share image, structured data.
     *
     * @param  array<array-key, mixed>  $seo
     * @return int
     */
    private static function seo(array $seo): int
    {
        $title = (string) ($seo['title'] ?? '');
        $description = (string) ($seo['description'] ?? '');
        $images = (int) ($seo['images'] ?? 0);
        $score = 100;
        $score -= $title === '' ? 20 : (mb_strlen($title) < 15 || mb_strlen($title) > 65 ? 6 : 0);
        $score -= $description === '' ? 15 : (mb_strlen($description) < 50 || mb_strlen($description) > 170 ? 5 : 0);
        $score -= (int) ($seo['h1Count'] ?? 0) === 1 ? 0 : 10;
        $score -= blank($seo['canonical'] ?? null) ? 8 : 0;
        $score -= blank($seo['lang'] ?? null) ? 7 : 0;
        $score -= $images > 0 ? (int) round(15 * min(1, (int) ($seo['imagesWithoutAlt'] ?? 0) / $images)) : 0;
        $score -= blank($seo['ogImage'] ?? null) ? 5 : 0;
        $score -= (int) ($seo['structuredData'] ?? 0) > 0 ? 0 : 5;
        $score -= str_contains(strtolower((string) ($seo['robots'] ?? '')), 'noindex') ? 30 : 0;

        return self::clamp($score);
    }

    /**
     * Score the phone layout: a viewport tag, no sideways scrolling, tap targets big enough, readable text.
     *
     * @param  array<array-key, mixed>  $mobile
     * @param  array<array-key, mixed>  $seo
     * @return int
     */
    private static function mobile(array $mobile, array $seo): int
    {
        $targets = max(1, (int) ($mobile['tapTargets'] ?? 0));
        $score = 100;
        $score -= blank($seo['viewport'] ?? null) ? 30 : 0;
        $score -= ($mobile['horizontalOverflow'] ?? false) === true ? 25 : 0;
        $score -= (int) round(30 * min(1, (int) ($mobile['smallTapTargets'] ?? 0) / $targets));
        $score -= min(15, (int) ($mobile['smallText'] ?? 0));

        return self::clamp($score);
    }

    /**
     * Get the penalty for a value by which band it falls in: up to good, up to fair, up to poor, or beyond.
     *
     * @param  int  $value
     * @param  int  $good
     * @param  int  $fair
     * @param  int  $poor
     * @param  array{0: int, 1: int, 2: int, 3: int}  $penalties
     * @return int
     */
    private static function band(int $value, int $good, int $fair, int $poor, array $penalties): int
    {
        return match (true) {
            $value <= $good => $penalties[0],
            $value <= $fair => $penalties[1],
            $value <= $poor => $penalties[2],
            default => $penalties[3],
        };
    }

    /**
     * Keep a score between 0 and 100.
     *
     * @param  int  $score
     * @return int
     */
    private static function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
