<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusIncident extends DeployerModel
{
    public const KINDS = ['incident', 'maintenance'];

    public const STATUSES = ['investigating', 'identified', 'monitoring', 'resolved', 'scheduled', 'in_progress', 'completed'];

    public const SEVERITIES = ['minor', 'major', 'critical'];

    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'resolved_at' => 'datetime'];

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }
}
