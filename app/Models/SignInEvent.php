<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignInEvent extends Model
{
    use HasFactory;

    public const METHOD_PASSWORD = 'password';

    public const METHODS = [
        self::METHOD_PASSWORD,
        'github',
        'gitlab',
        'bitbucket',
    ];

    public $timestamps = false;

    protected $fillable = [
        'method',
        'ip_address',
        'user_agent',
        'signed_in_at',
    ];

    protected $casts = [
        'signed_in_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
