<?php

namespace Tests\Unit;

use App\Support\PublicIpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicIpAddressTest extends TestCase
{
    #[DataProvider('addresses')]
    public function test_only_global_unicast_addresses_are_public(string $address, bool $expected): void
    {
        $this->assertSame($expected, PublicIpAddress::isValid($address));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'public IPv4' => ['1.1.1.1', true];
        yield 'public IPv6' => ['2606:4700:4700::1111', true];
        yield 'loopback IPv4' => ['127.0.0.1', false];
        yield 'loopback IPv6' => ['::1', false];
        yield 'private IPv4' => ['10.0.0.1', false];
        yield 'private IPv6' => ['fd00::1', false];
        yield 'metadata IPv4' => ['169.254.169.254', false];
        yield 'link-local IPv6' => ['fe80::1', false];
        yield 'carrier NAT' => ['100.64.0.1', false];
        yield 'documentation IPv4' => ['192.0.2.1', false];
        yield 'documentation IPv6' => ['2001:db8::1', false];
        yield 'multicast IPv4' => ['224.0.0.1', false];
        yield 'multicast IPv6' => ['ff02::1', false];
        yield 'mapped private IPv4' => ['::ffff:127.0.0.1', false];
        yield 'unspecified IPv4' => ['0.0.0.0', false];
        yield 'unspecified IPv6' => ['::', false];
        yield 'hostname' => ['example.com', false];
        yield 'empty' => ['', false];
    }
}
