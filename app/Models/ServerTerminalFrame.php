<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Keystrokes on their way to the server (`in`) or terminal output on its way to the browser (`out`). Encrypted, and
 * deleted as soon as the other side has taken it (`terminals:expire` removes leftovers).
 *
 * @property int $id
 * @property string $server_terminal_session_id
 * @property string $direction in or out
 * @property int $sequence
 * @property string $payload
 * @property int $bytes
 * @property Carbon|null $created_at
 */
#[Hidden(['payload'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServerTerminalFrame extends Model
{
    public const UPDATED_AT = null;

    /**
     * The terminal the frame belongs to.
     *
     * @return BelongsTo<ServerTerminalSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ServerTerminalSession::class, 'server_terminal_session_id');
    }

    /**
     * Encrypts `payload`, the keystrokes or output it carries.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'encrypted', 'sequence' => 'integer', 'bytes' => 'integer'];
    }
}
