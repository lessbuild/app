<?php

declare(strict_types=1);

namespace App\Enums;

/** What an Audit finding or score is about. The first four are measured in the browser; the rest are judged from the journeys. */
enum SiteAuditCategory: string
{
    case Performance = 'performance';
    case Accessibility = 'accessibility';
    case Seo = 'seo';
    case Mobile = 'mobile';
    case Navigation = 'navigation';
    case Conversion = 'conversion';
    case Content = 'content';
    case Trust = 'trust';

    /**
     * Get the category's name as people read it.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Performance => __('Speed'),
            self::Accessibility => __('Accessibility'),
            self::Seo => __('Search'),
            self::Mobile => __('Mobile'),
            self::Navigation => __('Navigation'),
            self::Conversion => __('Conversion'),
            self::Content => __('Clarity'),
            self::Trust => __('Trust'),
        };
    }

    /**
     * Get how much the category counts towards the overall score; the flows people take count most.
     *
     * @return int
     */
    public function weight(): int
    {
        return match ($this) {
            self::Navigation, self::Conversion => 3,
            self::Content, self::Performance, self::Mobile => 2,
            self::Accessibility, self::Seo, self::Trust => 1,
        };
    }

    /**
     * Determine whether the browser measures the category, rather than Claude judging it from the journeys.
     *
     * @return bool
     */
    public function measured(): bool
    {
        return in_array($this, [self::Performance, self::Accessibility, self::Seo, self::Mobile], true);
    }
}
