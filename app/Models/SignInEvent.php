<?php

namespace App\Models;

use App\Enums\SignInMethod;
use App\Presenters\SignInEventPresenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignInEvent extends Model
{
    use HasFactory;

    public const METHOD_PASSWORD = SignInMethod::Password->value;

    public const METHODS = SignInMethod::VALUES;

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

    /**
     * Interpret the recorded authentication method while retaining legacy string data.
     *
     * @return SignInMethod|null The known method, or null for absent or historical values.
     */
    public function methodEnum(): ?SignInMethod
    {
        $method = $this->getAttribute('method');

        return $method instanceof SignInMethod
            ? $method
            : (is_string($method) ? SignInMethod::tryFrom($method) : null);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return string The user-facing label for this event's stored sign-in method. */
    public function methodName(): string
    {
        return static::methodLabel($this->method);
    }

    /**
     * @param  string  $method  The stored sign-in method, including unknown historical values.
     * @return string The provider name or translated password/unknown label.
     */
    public static function methodLabel(string $method): string
    {
        return SignInEventPresenter::methodLabel($method);
    }
}
