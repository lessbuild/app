<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The read-only inspection of a server someone wants to import. It can be confirmed once, by them, within 30 minutes.
 *
 * @property int $id
 * @property string $account_id
 * @property string $user_id
 * @property string $token_hash
 * @property array{name: string, type: string, public_ip: string, ssh_port: int, ssh_private_key: string} $configuration
 * @property array<string, mixed> $report
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['token_hash', 'configuration'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServerImportAssessment extends Model
{
    public function isUsableBy(User $user, string $accountId, string $token): bool
    {
        return $this->user_id === $user->id && $this->account_id === $accountId && $this->consumed_at === null
            && $this->expires_at->isFuture() && hash_equals($this->token_hash, hash('sha256', $token));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['configuration' => 'encrypted:array', 'report' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
