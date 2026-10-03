<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Models\Project;
use App\Models\SecurityBlock;
use App\Models\User;
use App\Services\Security\AttackWatch;
use Illuminate\Support\Facades\Gate;

final class LiftSecurityBlock
{
    /**
     * Create a new LiftSecurityBlock instance.
     *
     * @param  AttackWatch  $watch  Removes the block from the server.
     */
    public function __construct(private readonly AttackWatch $watch) {}

    /**
     * Unblock an address before its block expires, such as when a real person was caught by it.
     *
     * @param  User  $actor
     * @param  SecurityBlock  $block
     * @return void
     */
    public function handle(User $actor, SecurityBlock $block): void
    {
        Gate::forUser($actor)->authorize('manageService', [Project::query()->findOrFail($block->project_id), 'security']);
        if ($block->lifted_at === null) {
            $this->watch->lift($block->loadMissing('server'), $actor->id);
        }
    }
}
