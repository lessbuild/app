<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Scripts\Repository\ArtisanCommandsScript;
use App\Modules\Deployer\Scripts\Repository\ConfigureResourcesScript;
use App\Modules\Deployer\Scripts\Repository\InstallDependenciesScript;
use App\Modules\Deployer\Services\RepositoryDeploymentPlan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RepositoryResourcePreparationTest extends TestCase
{
    #[DataProvider('resourceSnapshots')]
    public function test_resources_are_prepared_before_dependency_hooks_and_migrations(?bool $managed, int $prepareResult): void
    {
        $resource = [
            'type' => 'postgresql',
            'configuration' => ['variables' => [
                'DB_HOST' => '127.0.0.1',
                'DB_DATABASE' => 'preview_test',
                'DB_USERNAME' => 'preview_test',
                'DB_PASSWORD' => "fixture'password",
            ]],
        ];
        if ($managed !== null) {
            $resource['is_managed'] = $managed;
        }
        $website = new Website(['deployment_slug' => 'preparation-test']);
        $repository = (new Repository)->setRelation('website', $website);
        $build = (new Build(['environment_payload' => ['resources' => [$resource]]]))
            ->setRelation('repository', $repository);
        $build->id = 42;

        $plan = app(RepositoryDeploymentPlan::class);
        $script = '';
        foreach ([InstallDependenciesScript::class, ArtisanCommandsScript::class, ConfigureResourcesScript::class] as $class) {
            $script .= app($class)->script($plan->stageFor($class), $build)."\n";
        }

        // Only fixture file checks and Bash execution are real. All remote commands,
        // package installation, application hooks and callbacks are intercepted.
        $protocol = <<<'BASH'
        set -Eeuo pipefail
        cd() { [[ "$*" == '-- /var/www/preparation-test/setup' ]]; }
        apt-get() { return 99; }
        docker() { return 99; }
        systemctl() { [[ "$*" == 'enable --now postgresql' ]]; }
        sudo() {
            [[ "$1" == '-u' && "$2" == postgres ]] || return 98
            shift 2
            "$@"
        }
        psql() {
            [[ "$*" == '--set=ON_ERROR_STOP=1 postgres' ]] || return 98
            local sql
            sql="$(cat)"
            [[ "$sql" == *'CREATE DATABASE "preview_test" OWNER "preview_test"'* ]] || return 98
            [[ "$sql" == *"PASSWORD 'fixture''password'"* ]] || return 98
            if [[ "$BP_TEST_PREPARE_RESULT" != 0 ]]; then
                return "$BP_TEST_PREPARE_RESULT"
            fi
            BP_TEST_DATABASE_READY=1
            printf 'resources\n'
        }
        composer() {
            [[ "$BP_TEST_DATABASE_READY" == 1 ]] || return 45
            printf 'dependencies\n'
        }
        php() {
            if [[ "$*" == 'artisan migrate --force' ]]; then
                [[ "$BP_TEST_DATABASE_READY" == 1 ]] || return 46
                printf 'migrations\n'
            fi
        }
        curl() {
            while [[ "$#" -gt 0 ]]; do
                if [[ "$1" == '--data' ]]; then
                    printf 'progress:%s\n' "$2"
                    return 0
                fi
                shift
            done
            return 98
        }
        BASH;

        $directory = sys_get_temp_dir().'/buildpusher-resource-preparation-'.Str::uuid();
        File::makeDirectory($directory, 0700);
        try {
            File::put($directory.'/composer.json', '{}');
            File::put($directory.'/artisan', '');
            $process = new Process(['bash', '-s'], $directory, [
                'BP_TEST_DATABASE_READY' => $managed === false ? '1' : '0',
                'BP_TEST_PREPARE_RESULT' => (string) $prepareResult,
            ]);
            $process->setInput($protocol."\n".$script)->run();

            $resources = $managed === false ? '' : "resources\n";
            $expected = $resources."dependencies\nprogress:status=4&build_id=42\nmigrations\nprogress:status=7&build_id=42\n"
                .$resources."progress:status=11&build_id=42\n";
            $this->assertSame($prepareResult, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame($prepareResult === 0 ? $expected : '', $process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** @return array<string, array{?bool, int}> */
    public static function resourceSnapshots(): array
    {
        return [
            'managed first deployment' => [true, 0],
            'legacy managed snapshot' => [null, 0],
            'external resource left unchanged' => [false, 0],
            'failed preparation stops before hooks and progress' => [true, 42],
        ];
    }
}
