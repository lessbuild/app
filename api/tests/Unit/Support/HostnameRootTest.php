<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Hostname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostnameRootTest extends TestCase
{
    /**
     * Names, the domain registered for them, and how a DNS provider's Host field wants them.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function names(): array
    {
        return [
            'apex record' => ['_buildpusher.vacenly.com', 'vacenly.com', '_buildpusher'],
            'subdomain record' => ['_buildpusher.api.vacenly.com', 'vacenly.com', '_buildpusher.api'],
            'two-label ending' => ['_buildpusher.shop.co.uk', 'shop.co.uk', '_buildpusher'],
            'deep under a two-label ending' => ['_buildpusher.www.shop.com.au', 'shop.com.au', '_buildpusher.www'],
            'the domain itself' => ['example.com', 'example.com', '@'],
            'trailing dot' => ['_buildpusher.example.com.', 'example.com', '_buildpusher'],
        ];
    }

    /**
     * Check the registered domain and the Host form of a name.
     *
     * @param  string  $name
     * @param  string  $root
     * @param  string  $host
     * @return void
     */
    #[DataProvider('names')]
    public function test_root_and_host(string $name, string $root, string $host): void
    {
        $this->assertSame($root, Hostname::root($name));
        $this->assertSame($host, Hostname::host($name));
    }
}
