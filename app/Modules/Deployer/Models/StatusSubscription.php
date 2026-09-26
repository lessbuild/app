<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusSubscription extends DeployerModel
{
    protected $guarded = [];

    protected $hidden = ['email', 'email_hash', 'verification_token_hash', 'unsubscribe_token'];

    protected $casts = [
        'email' => 'encrypted',
        'unsubscribe_token' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }
}
