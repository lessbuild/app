<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\Server;
use App\Models\ToolMove;
use App\Models\User;
use App\Services\Infrastructure\ToolInventory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ConnectToolMove
{
    /**
     * Create a new ConnectToolMove instance.
     *
     * @param  ToolInventory  $inventory  Reads the other tool.
     */
    public function __construct(private readonly ToolInventory $inventory) {}

    /**
     * Read the servers and sites in Laravel Forge or Ploi with an API token, keeping them to move site by site. Moving
     * again from the same tool reads it afresh and remembers which sites were already moved.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string  $source  forge or ploi
     * @param  string  $token
     * @return ToolMove
     *
     * @throws ValidationException when the tool refuses the token
     */
    public function handle(User $actor, Account $account, string $source, string $token): ToolMove
    {
        Gate::forUser($actor)->authorize('create', [Server::class, $account]);
        try {
            $inventory = $this->inventory->read($source, trim($token));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['token' => $exception->getMessage()]);
        }
        $move = ToolMove::query()->where('account_id', $account->id)->where('source', $source)->first() ?? new ToolMove;
        $move->forceFill([
            'account_id' => $account->id, 'created_by' => $move->created_by ?? $actor->id, 'source' => $source,
            'token' => trim($token), 'inventory' => $inventory, 'fetched_at' => now(),
        ])->save();

        return $move;
    }
}
