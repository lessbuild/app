<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Hostname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostnameTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function inputs(): iterable
    {
        yield 'plain' => ['shop.example.com', 'shop.example.com'];
        yield 'url with path and case' => ['https://Shop.Example.COM/cart?x=1', 'shop.example.com'];
        yield 'trailing dot' => ['example.com.', 'example.com'];
        yield 'unicode to punycode' => ['bücher.example', 'xn--bcher-kva.example'];
        yield 'port' => ['example.com:8080', null];
        yield 'ip address' => ['192.168.1.1', null];
        yield 'no tld' => ['localhost', null];
        yield 'numeric tld' => ['example.123', null];
        yield 'leading hyphen' => ['-bad.example.com', null];
        yield 'underscore' => ['_dmarc.example.com', null];
        yield 'empty' => ['  ', null];
    }

    #[DataProvider('inputs')]
    public function test_it_normalises_hostnames(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Hostname::normalize($input));
    }

    public function test_it_displays_punycode_as_unicode(): void
    {
        $this->assertSame('bücher.example', Hostname::display('xn--bcher-kva.example'));
    }
}
