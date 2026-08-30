<?php

namespace App\Services;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Scripts\Cache\InstallMemcachedScript;
use App\Scripts\Cache\InstallRedisScript;
use App\Scripts\Database\InstallMysqlScript;
use App\Scripts\Languages\InstallNodeScript;
use App\Scripts\Languages\InstallPHPScript;
use App\Scripts\Server\BaseScript;
use App\Scripts\Server\ConfigureServerScript;
use App\Scripts\Server\ConfigureSwapScript;
use App\Scripts\Server\EndScript;
use App\Scripts\Server\InstallComposerScript;
use App\Scripts\Server\RecipesScript;
use App\Scripts\Server\UpdateDependenciesScript;
use App\Scripts\Web\InstallCaddyScript;

class ServerProvisioningPlan
{
    /**
     * @return list<class-string>
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
     * @return list<class-string>
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

    public function finalStage(Server|ServerTypeEnum|string|null $serverOrType): int
    {
        return count($this->steps($serverOrType));
    }

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
