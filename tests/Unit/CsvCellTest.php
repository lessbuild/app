<?php

namespace Tests\Unit;

use App\Support\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvCellTest extends TestCase
{
    #[DataProvider('cells')]
    public function test_exported_cells_preserve_content_and_neutralize_spreadsheet_formulas(string|int|null $input, ?string $expected): void
    {
        $this->assertSame($expected, CsvCell::escape($input));
    }

    public static function cells(): array
    {
        return [
            [null, null], [0, '0'], ['ordinary text', 'ordinary text'], ['', ''],
            ['=1+1', "'=1+1"], ["\t +1", "'\t +1"], ["\n\r-2", "'\n\r-2"],
            ['@formula', "'@formula"], ["\0=1", "'=1"], ["a\0b", 'ab'],
            ['one,two', 'one,two'], ['a "quote"', 'a "quote"'],
        ];
    }
}
