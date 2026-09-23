<?php

namespace App\Core\Database;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class CoreModel extends Model
{
    use HasUlids;

    protected $connection = 'core';

    public $incrementing = false;

    protected $keyType = 'string';
}
