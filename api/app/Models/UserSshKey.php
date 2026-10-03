<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's SSH public key, installed on the servers they're given access to.
 *
 * @property int $id
 * @property string $user_id
 * @property string $name
 * @property string $public_key the key's type and data, without its comment
 * @property string $fingerprint SHA256 fingerprint, as ssh-keygen shows it
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
final class UserSshKey extends Model
{
    /**
     * The key types accepted.
     *
     * @var list<string>
     */
    public const TYPES = ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com'];

    /**
     * The attributes that can't be mass assigned: all of them; keys are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the key's owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Read a pasted public key into its type and data (dropping any comment), with its SHA256 fingerprint. Null when
     * it isn't a supported public key.
     *
     * @param  string  $text
     * @return array{key: string, fingerprint: string}|null
     */
    public static function parse(string $text): ?array
    {
        $parts = preg_split('/\s+/', trim($text)) ?: [];
        if (count($parts) < 2 || ! in_array($parts[0], self::TYPES, true)) {
            return null;
        }
        $data = base64_decode($parts[1], true);
        if ($data === false || strlen($data) < 32 || ! str_contains($data, $parts[0])) {
            return null;
        }

        return ['key' => $parts[0].' '.$parts[1], 'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $data, true)), '=')];
    }
}
