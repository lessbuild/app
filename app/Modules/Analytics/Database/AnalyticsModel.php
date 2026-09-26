<?php

namespace App\Modules\Analytics\Database;

use Illuminate\Database\Eloquent\Model;

abstract class AnalyticsModel extends Model
{
    protected $connection = 'analytics';
}
