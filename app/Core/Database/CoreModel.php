<?php

namespace App\Core\Database;

use Illuminate\Database\Eloquent\Model;

abstract class CoreModel extends Model
{
    protected $connection = 'core';
}
