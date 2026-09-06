<?php

namespace Tests\Unit;

use App\Support\DateRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    #[DataProvider('ranges')]
    public function test_ranges_are_validated_and_normalized(string $from, string $to, array $expected): void
    {
        $this->assertSame($expected, DateRange::normalize($from, $to));
    }

    public static function ranges(): array
    {
        return [
            'chronological' => ['2026-01-01', '2026-01-31', ['2026-01-01', '2026-01-31']],
            'reversed' => ['2026-01-31', '2026-01-01', ['2026-01-01', '2026-01-31']],
            'equal' => ['2026-01-15', '2026-01-15', ['2026-01-15', '2026-01-15']],
            'invalid from' => ['2026-02-30', '2026-03-01', [null, '2026-03-01']],
            'invalid to' => ['2026-03-01', 'not-a-date', ['2026-03-01', null]],
            'empty' => ['', '', [null, null]],
        ];
    }
}
