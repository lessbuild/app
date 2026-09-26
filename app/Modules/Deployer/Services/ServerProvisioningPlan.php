<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Contracts\Scripts\ServerScript;
use App\Modules\Deployer\Models\Enums\Server\ServerTypeEnum;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Scripts\Cache\InstallMemcachedScript;
use App\Modules\Deployer\Scripts\Cache\InstallRedisScript;
use App\Modules\Deployer\Scripts\Database\InstallMysqlScript;
use App\Modules\Deployer\Scripts\Languages\InstallNodeScript;
use App\Modules\Deployer\Scripts\Languages\InstallPHPScript;
use App\Modules\Deployer\Scripts\Server\BaseScript;
use App\Modules\Deployer\Scripts\Server\ConfigureServerScript;
use App\Modules\Deployer\Scripts\Server\ConfigureSwapScript;
use App\Modules\Deployer\Scripts\Server\EndScript;
use App\Modules\Deployer\Scripts\Server\InstallComposerScript;
use App\Modules\Deployer\Scripts\Server\RecipesScript;
use App\Modules\Deployer\Scripts\Server\UpdateDependenciesScript;
use App\Modules\Deployer\Scripts\Web\InstallCaddyScript;

class ServerProvisioningPlan
{
    /**
     * @return list<class-string<ServerScript>>
     */
    public function scripts(Server|ServerTypeEnum|string|null $serverOrType): array
    {
        $type = $this->type($serverOrType);

        return [
            BaseScript::class,
            ...$this->steps($type),
        ];
    }

    /**
     * @return list<class-string<ServerScript>>
     */
    public function steps(Server|ServerTypeEnum|string|null $serverOrType): array
    {
        $type = $this->type($serverOrType);
        $common = [
            UpdateDependenciesScript::class,
            ConfigureSwapScript::class,
            ConfigureServerScript::class,
        ];

        $specific = match ($type) {
            ServerTypeEnum::app => [
                InstallComposerScript::class,
                InstallPHPScript::class,
                InstallNodeScript::class,
                InstallCaddyScript::class,
                InstallMysqlScript::class,
                InstallRedisScript::class,
                InstallMemcachedScript::class,
            ],
            ServerTypeEnum::web => [
                InstallComposerScript::class,
                InstallPHPScript::class,
                InstallCaddyScript::class,
            ],
            ServerTypeEnum::worker => [
                InstallComposerScript::class,
                InstallPHPScript::class,
                InstallNodeScript::class,
            ],
            ServerTypeEnum::database => [InstallMysqlScript::class],
            ServerTypeEnum::cache => [InstallRedisScript::class, InstallMemcachedScript::class],
            ServerTypeEnum::loadbalancer => [InstallCaddyScript::class],
        };

        return [
            ...$common,
            ...$specific,
            RecipesScript::class,
            EndScript::class,
        ];
    }

    /**
     * Count provisioning steps for the selected server role.
     *
     * @param  Server|ServerTypeEnum|string|null  $serverOrType  A server, role enum or stored role string; unknown values use the application role.
     * @return int The completion stage for that role's provisioning plan.
     */
    public function finalStage(Server|ServerTypeEnum|string|null $serverOrType): int
    {
        return count($this->steps($serverOrType));
    }

    /**
     * Normalize a server or role value to a supported provisioning role.
     *
     * @param  Server|ServerTypeEnum|string|null  $serverOrType  A server, role enum, stored string or absent value.
     * @return ServerTypeEnum The matching role enum, defaulting to the application-server role.
     */
    private function type(Server|ServerTypeEnum|string|null $serverOrType): ServerTypeEnum
    {
        if ($serverOrType instanceof Server) {
            $serverOrType = $serverOrType->type;
        }

        if ($serverOrType instanceof ServerTypeEnum) {
            return $serverOrType;
        }

        return ServerTypeEnum::tryFrom((string) $serverOrType) ?? ServerTypeEnum::app;
    }
}
