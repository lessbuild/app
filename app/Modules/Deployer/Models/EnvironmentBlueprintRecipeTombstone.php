<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Minimal signed evidence that an accepted recipe slot was archived. */
final class EnvironmentBlueprintRecipeTombstone extends DeployerModel
{
    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['slot_commitment', 'tombstone_signature'];

    protected $casts = [
        'position' => 'integer',
        'archived_at' => 'datetime',
        'signature_version' => 'integer',
    ];

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return MorphMany<Event, $this> */
    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }
}
