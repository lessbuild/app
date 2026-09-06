<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvironmentVariableVersion extends Model
{
    protected $guarded = [];

    protected $hidden = ['value'];

    protected $casts = ['value' => 'encrypted', 'version' => 'integer'];

    /** @return BelongsTo<EnvironmentVariable, $this> */
    public function variable(): BelongsTo
    {
        return $this->belongsTo(EnvironmentVariable::class, 'environment_variable_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
