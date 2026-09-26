<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An immutable, Deployer-local recipe snapshot prepared for one blueprint environment. */
final class EnvironmentBlueprintRecipe extends DeployerModel
{
    protected $guarded = [];

    protected $hidden = ['script_snapshot', 'script_fingerprint', 'binding_fingerprint', 'install_receipt_fingerprint'];

    protected $casts = [
        'script_snapshot' => 'encrypted',
        'source_is_published' => 'boolean',
        'source_updated_at' => 'datetime',
        'source_revision_at' => 'datetime',
        'source_published_at' => 'datetime',
        'source_gallery_revision_at' => 'datetime',
        'install_attempted_at' => 'datetime',
    ];

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Recipe, $this> Historical provenance; the source recipe may have been deleted. */
    public function sourceRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'source_recipe_id');
    }

    /** @return BelongsTo<Recipe, $this> */
    public function installedRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'installed_recipe_id');
    }
}
