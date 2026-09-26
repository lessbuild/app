<?php

namespace App\Modules\Deployer\Data;

use InvalidArgumentException;

final readonly class ServerTroubleshootingTerminalSize
{
    public function __construct(
        public int $columns = 80,
        public int $rows = 24,
    ) {
        $minimumColumns = max(1, (int) config('lessbuild.troubleshooting.terminal_min_columns', 20));
        $maximumColumns = max($minimumColumns, (int) config('lessbuild.troubleshooting.terminal_max_columns', 240));
        $minimumRows = max(1, (int) config('lessbuild.troubleshooting.terminal_min_rows', 5));
        $maximumRows = max($minimumRows, (int) config('lessbuild.troubleshooting.terminal_max_rows', 100));

        if ($columns < $minimumColumns || $columns > $maximumColumns) {
            throw new InvalidArgumentException('The terminal column count is outside the supported range.');
        }

        if ($rows < $minimumRows || $rows > $maximumRows) {
            throw new InvalidArgumentException('The terminal row count is outside the supported range.');
        }
    }

    /** Serialize validated dimensions as the bounded control frame consumed by the broker. */
    public function controlFrame(): string
    {
        return sprintf("stty rows %d cols %d\n", $this->rows, $this->columns);
    }
}
