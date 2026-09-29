<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\ServerScript;
use App\Enums\ServerType;
use App\Models\Server;
use App\Services\Infrastructure\Scripts\Cache\InstallMemcachedScript;
use App\Services\Infrastructure\Scripts\Cache\InstallRedisScript;
use App\Services\Infrastructure\Scripts\Database\InstallMysqlScript;
use App\Services\Infrastructure\Scripts\Database\InstallPostgresScript;
use App\Services\Infrastructure\Scripts\Languages\InstallNodeScript;
use App\Services\Infrastructure\Scripts\Languages\InstallPHPScript;
use App\Services\Infrastructure\Scripts\Server\BaseScript;
use App\Services\Infrastructure\Scripts\Server\ConfigureServerScript;
use App\Services\Infrastructure\Scripts\Server\ConfigureSwapScript;
use App\Services\Infrastructure\Scripts\Server\EndScript;
use App\Services\Infrastructure\Scripts\Server\InstallComposerScript;
use App\Services\Infrastructure\Scripts\Server\RecipesScript;
use App\Services\Infrastructure\Scripts\Server\UpdateDependenciesScript;
use App\Services\Infrastructure\Scripts\Web\InstallCaddyScript;

/** The provisioning stages for each server type. Stage N is the Nth step; the base script (stage 0) sets up logging and callbacks. */
final class ServerProvisioningPlan
{
    /**
     * List every provisioning script for the server's type, starting with the base script.
     *
     * @param  Server|ServerType  $server
     * @return list<class-string<ServerScript>>
     */
    public function scripts(Server|ServerType $server): array
    {
        return [BaseScript::class, ...$this->steps($server)];
    }

    /**
     * List the stages after the base script: updates, swap and server configuration, the type's software, recipes, and
     * the finish.
     *
     * @param  Server|ServerType  $server
     * @return list<class-string<ServerScript>>
     */
    public function steps(Server|ServerType $server): array
    {
        $type = $server instanceof Server ? $server->type : $server;
        $specific = match ($type) {
            ServerType::App => [InstallComposerScript::class, InstallPHPScript::class, InstallNodeScript::class, InstallCaddyScript::class, InstallMysqlScript::class, InstallRedisScript::class, InstallMemcachedScript::class],
            ServerType::Web => [InstallComposerScript::class, InstallPHPScript::class, InstallCaddyScript::class],
            ServerType::Worker => [InstallComposerScript::class, InstallPHPScript::class, InstallNodeScript::class],
            ServerType::Database => [$server instanceof Server && $server->database_engine === 'postgres' ? InstallPostgresScript::class : InstallMysqlScript::class],
            ServerType::Cache => [InstallRedisScript::class, InstallMemcachedScript::class],
            ServerType::LoadBalancer => [InstallCaddyScript::class],
        };

        return [UpdateDependenciesScript::class, ConfigureSwapScript::class, ConfigureServerScript::class, ...$specific, RecipesScript::class, EndScript::class];
    }

    /**
     * Count the stages, which is the last progress value the script reports.
     *
     * @param  Server|ServerType  $server
     * @return int
     */
    public function finalStage(Server|ServerType $server): int
    {
        return count($this->steps($server));
    }

    /**
     * Describe the step a server is on now (the one after its last confirmed stage), or null before setup starts
     * running steps or once they're done.
     *
     * @param  Server  $server
     * @return string|null
     */
    public function currentStep(Server $server): ?string
    {
        $step = $this->steps($server)[$server->setup_stage] ?? null;

        $label = match ($step) {
            UpdateDependenciesScript::class => 'Updating system packages',
            ConfigureSwapScript::class => 'Setting up swap',
            ConfigureServerScript::class => 'Configuring the server',
            InstallComposerScript::class => 'Installing Composer',
            InstallPHPScript::class => 'Installing PHP',
            InstallNodeScript::class => 'Installing Node',
            InstallCaddyScript::class => 'Installing Caddy',
            InstallMysqlScript::class => 'Installing MySQL',
            InstallRedisScript::class => 'Installing Redis',
            InstallMemcachedScript::class => 'Installing Memcached',
            RecipesScript::class => 'Running recipes',
            EndScript::class => 'Finishing up',
            default => null,
        };
        $translated = $label === null ? null : __($label);

        return is_string($translated) ? $translated : $label;
    }
}
