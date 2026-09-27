<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

use InvalidArgumentException;

/**
 * The window size of a troubleshooting terminal, in character cells. The limits keep a browser from asking the
 * server for an absurd pseudo-terminal; the open request validates against the same constants.
 */
final readonly class TerminalSize
{
    public const MIN_COLUMNS = 20;

    public const MAX_COLUMNS = 240;

    public const MIN_ROWS = 5;

    public const MAX_ROWS = 100;

    /**
     * Refuses sizes outside the limits instead of clamping them, since callers have already validated or clamped.
     *
     * @param  int  $columns  Characters per line.
     * @param  int  $rows  Lines on screen.
     *
     * @throws InvalidArgumentException when either dimension is out of range
     */
    public function __construct(
        public int $columns = 80,
        public int $rows = 24,
    ) {
        if ($columns < self::MIN_COLUMNS || $columns > self::MAX_COLUMNS) {
            throw new InvalidArgumentException('The terminal column count is outside the supported range.');
        }

        if ($rows < self::MIN_ROWS || $rows > self::MAX_ROWS) {
            throw new InvalidArgumentException('The terminal row count is outside the supported range.');
        }
    }
}
