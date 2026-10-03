<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/** Known referrer-spam domains: sites that fake visits so their name shows up in analytics. */
final class ReferrerSpam
{
    /**
     * The built-in list.
     *
     * @var list<string>
     */
    public const DOMAINS = [
        'semalt.com', 'buttons-for-website.com', 'buttons-for-your-website.com', 'darodar.com', 'ilovevitaly.com', 'ilovevitaly.ru', 'priceg.com',
        'hulfingtonpost.com', 'bestwebsitesawards.com', 'o-o-6-o-o.com', 'best-seo-offer.com', '4webmasters.org', 'trafficmonetize.com',
        'free-share-buttons.com', 'social-buttons.com', 'event-tracking.com', 'get-free-traffic-now.com', 'success-seo.com',
        'videos-for-your-business.com', 'floating-share-buttons.com', 'simple-share-buttons.com', 'sharebutton.net', 'site-auditor.online',
        'rank-checker.online', 'seo-platform.com', 'website-analyzer.info', 'copyrightclaims.org', 'traffic2cash.xyz', 'webmonetizer.net',
        'free-video-tool.com', 'blackhatworth.com', 'anticrawler.org', 'hundejo.com', 'kambasoft.com', 'savetubevideo.com', 'screentoolkit.com',
        'econom.co', 'descargar-musica-gratis.net', 'cenokos.ru', 'cenoval.ru', 'makemoneyonline.com', 'best-seo-solution.com', 'seo-2-0.com',
    ];

    /**
     * Determine whether a referring host is spam: on the built-in list or the site's own, or a subdomain of either.
     *
     * @param  string|null  $host
     * @param  list<string>  $extra  the site's blocked referrers
     * @return bool
     */
    public static function matches(?string $host, array $extra = []): bool
    {
        if ($host === null || $host === '') {
            return false;
        }
        foreach ([...self::DOMAINS, ...$extra] as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }
}
