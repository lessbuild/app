<?php

namespace App\Modules\Monitor\Database;

use Illuminate\Database\Eloquent\Model;

abstract class MonitorModel extends Model
{
    protected $connection = 'monitor';
}
