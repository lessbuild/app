<?php

declare(strict_types=1);

namespace App\Services\SiteAudits;

use App\Contracts\Monitoring\DnsResolver;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads a public web page's title, description and text, for suggesting competitors. Only public addresses are
 * fetched: each hop's host is resolved first, refused if any address is private or reserved, and the connection is
 * pinned to the checked address so the name can't be re-pointed in between.
 */
final class PublicPageFetcher
{
    /**
     * Create a new PublicPageFetcher instance.
     *
     * @param  DnsResolver  $dns  Resolves host names to addresses.
     */
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * Fetch a page, following up to three redirects.
     *
     * @param  string  $url
     * @return array{url: string, title: string, description: string, text: string}
     *
     * @throws RuntimeException when the address isn't public or the page can't be read
     */
    public function fetch(string $url): array
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            [$host, $port, $address] = $this->publicAddress($url);
            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($address, ':') ? "[{$address}]" : $address)]],
                ])->withUserAgent((string) config('site_audits.user_agent'))->connectTimeout(5)->timeout(10)->get($url);
            } catch (ConnectionException) {
                throw new RuntimeException(__('The site couldn’t be reached.'));
            }
            if ($response->redirect() && $response->header('Location') !== '') {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));

                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException(__('The site answered with HTTP :status.', ['status' => $response->status()]));
            }

            return $this->read($url, mb_substr($response->body(), 0, 2_000_000));
        }

        throw new RuntimeException(__('The site redirected too many times.'));
    }

    /**
     * Check a URL is http(s) on a public address, and get the host, port and the address to connect to.
     *
     * @param  string  $url
     * @return array{0: string, 1: int, 2: string}
     *
     * @throws RuntimeException
     */
    private function publicAddress(string $url): array
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? ''), '[]') : '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(__('Enter a public http or https address.'));
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->dns->addresses($host);
        foreach ($addresses === [] ? [''] : $addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException(__('Enter a public http or https address.'));
            }
        }

        return [$host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), $addresses[0]];
    }

    /**
     * Pull the title, description and readable text out of a page's HTML.
     *
     * @param  string  $url
     * @param  string  $html
     * @return array{url: string, title: string, description: string, text: string}
     */
    private function read(string $url, string $html): array
    {
        $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match) === 1 ? $match[1] : '';
        $description = preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $match) === 1 ? $match[1] : '';
        $body = (string) preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));

        return [
            'url' => $url,
            'title' => mb_substr(trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5)), 0, 200),
            'description' => mb_substr(trim(html_entity_decode($description, ENT_QUOTES | ENT_HTML5)), 0, 400),
            'text' => mb_substr($text, 0, 4000),
        ];
    }
}
