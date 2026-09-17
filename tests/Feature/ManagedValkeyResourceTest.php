<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Scripts\Repository\ConfigureResourcesScript;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ManagedValkeyResourceTest extends TestCase
{
    #[DataProvider('containerStarts')]
    public function test_resource_progress_requires_a_successful_container_start(bool $exists, int $startResult): void
    {
        $build = new Build([
            'environment_payload' => [
                'resources' => [[
                    'type' => 'valkey',
                    'is_managed' => true,
                    'configuration' => [
                        'container_name' => 'buildpusher-valkey-17-cache',
                        'variables' => [
                            'VALKEY_PORT' => '16396',
                            'REDIS_PASSWORD' => "preview'cache-secret",
                        ],
                    ],
                ]],
            ],
        ]);
        $build->id = 42;

        // Execute the actual generated Bash, but never invoke a host Docker daemon,
        // install packages, manage services or send a real callback.
        $protocol = <<<'BASH'
        set -Eeuo pipefail
        apt-get() { return 99; }
        systemctl() { return 99; }
        curl() { printf 'resource-progress\n'; }
        docker() {
            local operation="$1"
            shift
            case "$operation" in
                volume)
                    [[ "$*" == 'create buildpusher-valkey-17-cache-data' ]] || return 98
                    ;;
                container)
                    [[ "$*" == 'inspect buildpusher-valkey-17-cache' ]] || return 98
                    [[ "$BP_TEST_CONTAINER_EXISTS" == 1 ]]
                    ;;
                run)
                    [[ "$BP_TEST_CONTAINER_EXISTS" == 0 && "$#" == 15 ]] || return 98
                    [[ "$3" == 'buildpusher-valkey-17-cache' ]] || return 98
                    [[ "$7" == '127.0.0.1:16396:6379' ]] || return 98
                    [[ "$9" == 'buildpusher-valkey-17-cache-data:/data' ]] || return 98
                    [[ "${10}" == 'valkey/valkey:8-alpine' ]] || return 98
                    [[ "${14}" == '--requirepass' && "${15}" == "preview'cache-secret" ]] || return 98
                    return "$BP_TEST_START_RESULT"
                    ;;
                start)
                    [[ "$BP_TEST_CONTAINER_EXISTS" == 1 ]] || return 98
                    [[ "$*" == 'buildpusher-valkey-17-cache' ]] || return 98
                    return "$BP_TEST_START_RESULT"
                    ;;
                *) return 98 ;;
            esac
        }
        BASH;

        $process = new Process(['bash', '-s'], env: [
            'BP_TEST_CONTAINER_EXISTS' => $exists ? '1' : '0',
            'BP_TEST_START_RESULT' => (string) $startResult,
        ]);
        $process->setInput($protocol."\n".(new ConfigureResourcesScript)->script(3, $build));
        $process->run();

        $this->assertSame($startResult, $process->getExitCode());
        $this->assertSame($startResult === 0 ? "resource-progress\n" : '', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    /** @return array<string, array{bool, int}> */
    public static function containerStarts(): array
    {
        return [
            'new container starts' => [false, 0],
            'existing container starts' => [true, 0],
            'new container fails' => [false, 42],
            'existing container fails' => [true, 42],
        ];
    }
}
