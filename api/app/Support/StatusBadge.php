<?php

declare(strict_types=1);

namespace App\Support;

/** Draws the small two-part status badges (name on the left, state on the right) people put in READMEs and footers. */
final class StatusBadge
{
    /**
     * The badge colour for each overall state.
     *
     * @var array<string, string>
     */
    public const COLOURS = ['operational' => '#129b78', 'maintenance' => '#2563eb', 'degraded' => '#d97706', 'partial_outage' => '#d97706', 'major_outage' => '#dc2626'];

    /**
     * Draw a badge as SVG, sized to its text.
     *
     * @param  string  $name  the left part
     * @param  string  $label  the right part
     * @param  string  $colour  the right part's background, as #rrggbb
     * @return string
     */
    public static function svg(string $name, string $label, string $colour): string
    {
        $name = e(mb_substr($name, 0, 40));
        $label = e(mb_substr($label, 0, 40));
        $colour = preg_match('/\A#[0-9a-f]{6}\z/i', $colour) === 1 ? $colour : '#6b7280';
        $left = 7 * mb_strlen(html_entity_decode($name)) + 14;
        $right = 7 * mb_strlen(html_entity_decode($label)) + 14;
        $width = $left + $right;
        $nameX = $left / 2;
        $labelX = $left + $right / 2;

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="20" role="img" aria-label="{$name}: {$label}"><title>{$name}: {$label}</title><rect width="{$left}" height="20" fill="#172a4b"/><rect x="{$left}" width="{$right}" height="20" fill="{$colour}"/><g fill="#fff" font-family="Verdana,Geneva,sans-serif" font-size="11" text-anchor="middle"><text x="{$nameX}" y="14">{$name}</text><text x="{$labelX}" y="14">{$label}</text></g></svg>
            SVG;
    }

    /**
     * Get the response headers badges are served with: cached briefly by anyone, and never sniffed as anything else.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=60', 'X-Content-Type-Options' => 'nosniff'];
    }
}
