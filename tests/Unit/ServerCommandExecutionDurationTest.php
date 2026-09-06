<?php

namespace Tests\Unit;

use App\Models\ServerCommandExecution;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerCommandExecutionDurationTest extends TestCase
{
    #[DataProvider('durations')]
    public function test_command_runtime_is_derived_only_from_valid_complete_timestamps(
        ?string $startedAt,
        ?string $finishedAt,
        ?int $seconds,
        ?string $label,
    ): void {
        $execution = new ServerCommandExecution([
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        $this->assertSame($seconds, $execution->durationSeconds());
        $this->assertSame($label, $execution->durationLabel());
    }

    public static function durations(): array
    {
        return [
            'hours minutes and seconds' => ['2026-09-04 10:00:00', '2026-09-04 11:02:03', 3723, '1h 2m 3s'],
            'exact minute' => ['2026-09-04 10:00:00', '2026-09-04 10:01:00', 60, '1m'],
            'zero seconds' => ['2026-09-04 10:00:00', '2026-09-04 10:00:00', 0, '0s'],
            'not started' => [null, '2026-09-04 10:00:00', null, null],
            'not finished' => ['2026-09-04 10:00:00', null, null, null],
            'reversed timestamps' => ['2026-09-04 10:00:01', '2026-09-04 10:00:00', null, null],
        ];
    }
}
