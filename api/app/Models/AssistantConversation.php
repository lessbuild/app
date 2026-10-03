<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's conversation with the assistant in an account.
 *
 * @property string $id
 * @property string $account_id
 * @property string $user_id
 * @property string $title the first question, shortened
 * @property list<array{role: string, content: string|list<array<string, mixed>>}> $messages the exchange in Claude's message format, tool calls included (encrypted)
 * @property string $status idle, thinking or failed
 * @property string|null $error why the last answer failed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read User $user
 */
final class AssistantConversation extends Model
{
    use HasUlids;
    use MassPrunable;

    /**
     * Conversations are kept this many days after their last message; `model:prune` deletes older ones.
     *
     * @var int
     */
    public const RETENTION_DAYS = 30;

    /**
     * The attributes that can't be mass assigned: all of them; conversations are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['messages' => 'encrypted:array'];
    }

    /**
     * Get the account it's in.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the person asking.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the questions and answers to show: the person's text and the assistant's text, without tool calls, with an
     * answer's steps joined into one.
     *
     * @return list<array{role: string, text: string}>
     */
    public function transcript(): array
    {
        $lines = [];
        foreach ($this->messages as $message) {
            $text = is_string($message['content']) ? $message['content'] : implode("\n\n", array_filter(array_map(
                fn (array $block): ?string => ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null) ? $block['text'] : null,
                $message['content'],
            )));
            if (trim($text) === '') {
                continue;
            }
            $last = array_key_last($lines);
            if ($last !== null && $lines[$last]['role'] === 'assistant' && $message['role'] === 'assistant') {
                $lines[$last]['text'] .= "\n\n".$text;
            } else {
                $lines[] = ['role' => $message['role'], 'text' => $text];
            }
        }

        return $lines;
    }

    /**
     * Get the conversations old enough to delete.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('updated_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
