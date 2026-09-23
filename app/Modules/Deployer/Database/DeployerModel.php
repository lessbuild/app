<?php

namespace App\Modules\Deployer\Database;

use Illuminate\Database\Eloquent\Model;

abstract class DeployerModel extends Model
{
    protected $connection = 'deployer';
}
