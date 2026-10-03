<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Groups where a visit came from into marketing channels, the way Google Analytics' default channel grouping does:
 * Direct, Organic Search, Paid Search, Organic Social, Paid Social, Email, Affiliates, Display, Organic Video, SMS,
 * Referral and Unassigned.
 */
final class Channel
{
    /**
     * Every channel, in the order reports list them when counts tie.
     *
     * @var list<string>
     */
    public const ALL = ['Direct', 'Organic Search', 'Paid Search', 'Organic Social', 'Paid Social', 'Email', 'Affiliates', 'Display', 'Organic Video', 'SMS', 'Referral', 'Unassigned'];

    /**
     * Hosts (or their start, before the country suffix) of search engines.
     *
     * @var list<string>
     */
    private const SEARCH = ['google.', 'www.google.', 'bing.com', 'www.bing.com', 'duckduckgo.com', 'search.yahoo.com', 'yahoo.com', 'ecosia.org', 'www.ecosia.org', 'yandex.', 'baidu.com', 'www.baidu.com', 'search.brave.com', 'startpage.com', 'www.startpage.com', 'qwant.com', 'www.qwant.com', 'kagi.com', 'naver.com', 'search.naver.com', 'perplexity.ai', 'www.perplexity.ai', 'chatgpt.com'];

    /**
     * Hosts of social networks.
     *
     * @var list<string>
     */
    private const SOCIAL = ['facebook.com', 'm.facebook.com', 'l.facebook.com', 'lm.facebook.com', 'www.facebook.com', 'instagram.com', 'l.instagram.com', 'www.instagram.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'www.linkedin.com', 'lnkd.in', 'reddit.com', 'www.reddit.com', 'old.reddit.com', 'out.reddit.com', 'pinterest.com', 'www.pinterest.com', 'tiktok.com', 'www.tiktok.com', 'threads.net', 'www.threads.net', 'bsky.app', 'mastodon.social', 'news.ycombinator.com', 'discord.com', 'telegram.org', 't.me', 'whatsapp.com', 'web.whatsapp.com', 'quora.com', 'www.quora.com'];

    /**
     * Hosts of video sites.
     *
     * @var list<string>
     */
    private const VIDEO = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'vimeo.com', 'twitch.tv', 'www.twitch.tv'];

    /**
     * Work out the channel for a pageview from its UTM tags and referring host. A referrer from the site itself is
     * treated as no referrer.
     *
     * @param  string|null  $source  utm_source
     * @param  string|null  $medium  utm_medium
     * @param  string|null  $campaign  utm_campaign
     * @param  string|null  $referrer  the referring host
     * @param  list<string>  $ownHosts  the site's own domains
     * @return string one of ALL
     */
    public static function for(?string $source, ?string $medium, ?string $campaign, ?string $referrer, array $ownHosts = []): string
    {
        $source = strtolower(trim((string) $source));
        $medium = strtolower(trim((string) $medium));
        $campaign = strtolower(trim((string) $campaign));
        $referrer = strtolower(trim((string) $referrer));
        if ($referrer !== '' && self::isOwn($referrer, $ownHosts)) {
            $referrer = '';
        }
        $from = $source !== '' ? $source : $referrer;
        $paid = preg_match('/^(.*cp.*|ppc|retargeting|paid.*)$/', $medium) === 1;

        return match (true) {
            $source === '' && $medium === '' && $referrer === '' => 'Direct',
            $paid && (self::matches($from, self::SEARCH) || str_contains($campaign, 'shopping')) => 'Paid Search',
            $paid && self::matches($from, self::SOCIAL) => 'Paid Social',
            in_array($medium, ['display', 'banner', 'expandable', 'interstitial', 'cpm'], true) => 'Display',
            $paid => 'Paid Search',
            in_array($medium, ['email', 'e-mail', 'e_mail', 'e mail', 'newsletter'], true) || in_array($source, ['email', 'e-mail', 'newsletter'], true) => 'Email',
            $medium === 'affiliate' => 'Affiliates',
            in_array($medium, ['sms'], true) || $source === 'sms' => 'SMS',
            $medium === 'organic' || self::matches($from, self::SEARCH) => 'Organic Search',
            in_array($medium, ['social', 'social-network', 'social-media', 'sm', 'social network', 'social media'], true) || self::matches($from, self::SOCIAL) => 'Organic Social',
            $medium === 'video' || self::matches($from, self::VIDEO) => 'Organic Video',
            $referrer !== '' || $medium === 'referral' => 'Referral',
            default => 'Unassigned',
        };
    }

    /**
     * Determine whether a host or source name belongs to one of the lists: an exact host, a subdomain of it, or (for
     * entries ending in a dot) any country domain, such as google.co.uk. Bare names ("google", "facebook") match too.
     *
     * @param  string  $value
     * @param  list<string>  $hosts
     * @return bool
     */
    private static function matches(string $value, array $hosts): bool
    {
        if ($value === '') {
            return false;
        }
        foreach ($hosts as $host) {
            $bare = explode('.', ltrim(str_replace('www.', '', $host), '.'))[0];
            if ($value === $bare || $value === rtrim($host, '.') || str_ends_with($value, '.'.rtrim($host, '.')) || (str_ends_with($host, '.') && str_starts_with($value, $host))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a referrer is the site itself.
     *
     * @param  string  $referrer
     * @param  list<string>  $ownHosts
     * @return bool
     */
    private static function isOwn(string $referrer, array $ownHosts): bool
    {
        foreach ($ownHosts as $host) {
            $host = strtolower(ltrim($host, '*.'));
            if ($host !== '' && ($referrer === $host || str_ends_with($referrer, '.'.$host))) {
                return true;
            }
        }

        return false;
    }
}
