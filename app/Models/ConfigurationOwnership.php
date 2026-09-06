<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigurationOwnership extends Model
{
    public const KINDS = ['environment', 'processes', 'resources', 'variables'];

    protected $guarded = [];
}
