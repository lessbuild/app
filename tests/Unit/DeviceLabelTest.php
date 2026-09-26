<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Support\DeviceLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DeviceLabelTest extends TestCase
{
    /** @return iterable<string, array{string|null, string}> */
    public static function agents(): iterable
    {
        yield 'chrome on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', 'Chrome on Windows'];
        yield 'edge is not chrome' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/129.0 Safari/537.36 Edg/129.0', 'Edge on Windows'];
        yield 'safari on iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iPhone'];
        yield 'unknown' => ['curl/8.0', 'Unknown device'];
        yield 'missing' => [null, 'Unknown device'];
    }

    #[DataProvider('agents')]
    public function test_it_labels_common_browsers(?string $agent, string $label): void
    {
        $this->assertSame($label, DeviceLabel::from($agent));
    }
}
