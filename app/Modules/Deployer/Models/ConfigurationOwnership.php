<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;

class ConfigurationOwnership extends DeployerModel
{
    public const KINDS = ['environment', 'processes', 'resources', 'variables'];

    protected $guarded = [];
}
