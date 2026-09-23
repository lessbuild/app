<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationReview extends DeployerModel
{
    protected $guarded = [];

    protected $hidden = ['document', 'bindings'];

    protected $casts = [
        'document' => 'encrypted',
        'bindings' => 'encrypted:array',
        'summary' => 'array',
        'expires_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
