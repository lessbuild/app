<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\NativeDnsRecordResolver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class NativeDnsRecordResolverTest extends TestCase
{
    use MonitoringHelpers;

    public function test_uses_an_absolute_hostname_argument_and_a_bounded_process_timeout(): void
    {
        Process::preventStrayProcesses();
        Process::fake(['*DNS_TXT*' => Process::result(output: '[{"type":"TXT","txt":"hello"}]')]);

        $result = app(NativeDnsRecordResolver::class)->records('_dmarc.Example.com', 'TXT', 7);

        $this->assertSame([['type' => 'TXT', 'txt' => 'hello']], $result);
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[0] === PHP_BINARY
            && array_slice($process->command, -2) === ['_dmarc.example.com.', 'DNS_TXT']
            && $process->timeout === 7);
    }

    /**
     * @param  array<mixed>  $expected
     */
    #[DataProvider('processResults')]
    public function test_distinguishes_empty_answers_from_failed_or_invalid_resolver_output(string $output, int $exitCode, ?array $expected): void
    {
        Process::preventStrayProcesses();
        Process::fake(['*DNS_A*' => Process::result(output: $output, errorOutput: 'not persisted', exitCode: $exitCode)]);

        $result = app(NativeDnsRecordResolver::class)->records('status.example.com', 'A', 10);

        $this->assertSame($expected, $result);
    }

    /** @return array<string, list<mixed>> */
    public static function processResults(): array
    {
        return [
            'empty completed lookup' => ['[]', 0, []],
            'resolver failure' => ['', 1, null],
            'invalid JSON' => ['unexpected', 0, null],
            'wrong shape' => ['{"error":"secret"}', 0, null],
            'too large' => [str_repeat('x', 32769), 0, null],
        ];
    }

    public function test_process_exceptions_are_unknown_without_exposing_subprocess_details(): void
    {
        Process::preventStrayProcesses();
        Process::fake(['*DNS_A*' => function (): never {
            throw new RuntimeException('private process error');
        }]);

        $this->assertNull(app(NativeDnsRecordResolver::class)->records('status.example.com', 'A', 10));
    }

    public function test_invalid_hostnames_and_types_do_not_start_a_process(): void
    {
        Process::fake();

        $this->assertNull(app(NativeDnsRecordResolver::class)->records('example.com;id', 'A', 10));
        $this->assertNull(app(NativeDnsRecordResolver::class)->records('example.com', 'ANY', 10));
        Process::assertNothingRan();
    }
}
